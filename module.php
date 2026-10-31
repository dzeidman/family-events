<?php

/**
 * Family Events - a webtrees custom module.
 *
 * Shows upcoming birthdays, wedding anniversaries and death anniversaries,
 * filtered per event type by how closely each person is related to you,
 * or by explicitly named individuals.
 *
 * Copyright (C) 2026 David Zeidman
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleBlockInterface;
use Fisharebest\Webtrees\Module\ModuleBlockTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;

return new class extends AbstractModule implements ModuleCustomInterface, ModuleBlockInterface {
    use ModuleCustomTrait;
    use ModuleBlockTrait;

    // Event categories. Key => GEDCOM tag. Add more here (e.g. 'buri' => 'BURI') if needed.
    private const CATEGORIES = [
        'birt' => 'BIRT',
        'marr' => 'MARR',
        'deat' => 'DEAT',
    ];

    // Relationship scopes. The number is the maximum "kinship degree":
    // the larger of (generations up to the common ancestor, generations down from it).
    private const SCOPE_OFF   = 'off';
    private const SCOPE_NAMED = 'named';
    private const SCOPE_ALL   = 'all';

    private const SCOPE_DEGREES = [
        'immediate' => 1, // parents, siblings, children
        'close'     => 2, // + grandparents, grandchildren, aunts/uncles, nieces/nephews, 1st cousins
        'cousin2'   => 3, // + 2nd cousins
        'cousin3'   => 4, // + 3rd cousins
        'cousin4'   => 5, // + 4th cousins
    ];

    private const DEFAULTS = [
        'birt' => ['scope' => 'cousin3', 'spouses' => '1', 'living' => '1'],
        'marr' => ['scope' => 'close', 'spouses' => '0', 'living' => '1'],
        'deat' => ['scope' => 'immediate', 'spouses' => '1', 'living' => '0'],
    ];

    private const DEFAULT_DAYS = '14';
    private const MAX_DAYS     = 90;

    private CalendarService $calendar_service;

    /** @var array<string,Individual> Blood relatives found by the last search, keyed by xref */
    private array $people = [];

    public function __construct()
    {
        $this->calendar_service = Registry::container()->get(CalendarService::class);
    }

    // ------------------------------------------------------------------
    // Module metadata
    // ------------------------------------------------------------------

    public function title(): string
    {
        return I18N::translate('Family events');
    }

    public function description(): string
    {
        return I18N::translate('Upcoming birthdays and anniversaries, filtered by how closely each person is related to you.');
    }

    public function customModuleAuthorName(): string
    {
        return 'David Zeidman';
    }

    public function customModuleVersion(): string
    {
        return '1.0.0';
    }

    public function customModuleSupportUrl(): string
    {
        return '';
    }

    public function customModuleLatestVersionUrl(): string
    {
        return '';
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    // ------------------------------------------------------------------
    // Block placement
    // ------------------------------------------------------------------

    public function loadAjax(): bool
    {
        return true;
    }

    public function isUserBlock(): bool
    {
        return true;
    }

    public function isTreeBlock(): bool
    {
        return true;
    }

    // ------------------------------------------------------------------
    // Block content
    // ------------------------------------------------------------------

    /**
     * @param array<string,string> $config
     */
    public function getBlock(Tree $tree, int $block_id, string $context, array $config = []): string
    {
        $settings = $this->loadSettings($block_id);
        $root     = $this->rootIndividual($tree, $settings['root']);

        if ($root === null) {
            $content = view($this->name() . '::empty', [
                'message' => I18N::translate('To use this block, link your user account to your own individual record in this tree (My account), or choose a “Relative to” person in the block settings.'),
            ]);
        } else {
            $content = $this->buildContent($tree, $root, $settings);
        }

        if ($context !== self::CONTEXT_EMBED) {
            return view('modules/block-template', [
                'block'      => Str::kebab($this->name()),
                'id'         => $block_id,
                'config_url' => $this->configUrl($tree, $context, $block_id),
                'title'      => $this->title(),
                'content'    => $content,
            ]);
        }

        return $content;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function buildContent(Tree $tree, Individual $root, array $settings): string
    {
        // Work out which categories are switched on, and how far we need to search.
        $active     = [];
        $max_degree = 0;

        foreach (self::CATEGORIES as $key => $tag) {
            $scope = $settings[$key]['scope'];

            if ($scope === self::SCOPE_OFF) {
                continue;
            }

            $active[$key] = $tag;

            if (isset(self::SCOPE_DEGREES[$scope])) {
                $max_degree = max($max_degree, self::SCOPE_DEGREES[$scope]);
            }
        }

        if ($active === []) {
            return view($this->name() . '::empty', [
                'message' => I18N::translate('No event types are switched on. Use the block settings to choose some.'),
            ]);
        }

        $relatives = $this->findBloodRelatives($root, $max_degree);
        $spouses   = $this->findSpousesOfRelatives($relatives);

        $today = Registry::timestampFactory()->now()->julianDay();
        $start = $settings['today'] ? $today : $today + 1;
        $end   = $today + $settings['days'];

        $facts = $this->calendar_service->getEventsList($start, $end, implode('|', $active), false, 'anniv', $tree);

        $rows = [];

        foreach ($facts as $fact) {
            $parts = explode(':', $fact->tag());
            $key   = array_search(end($parts), $active, true);

            if ($key === false) {
                continue;
            }

            $match = $this->matchFact($fact, $settings[$key], $relatives, $spouses, $root);

            if ($match !== null) {
                $rows[] = [
                    'fact'         => $fact,
                    'relationship' => $match,
                    'days_away'    => $fact->jd - $today,
                ];
            }
        }

        if ($rows === []) {
            return view($this->name() . '::empty', [
                'message' => I18N::plural(
                    'No family events in the next %s day.',
                    'No family events in the next %s days.',
                    $settings['days'],
                    I18N::number($settings['days'])
                ),
            ]);
        }

        return view($this->name() . '::block', [
            'rows' => $rows,
        ]);
    }

    /**
     * Decide whether a fact passes the filter for its category.
     * Returns a relationship label if it does, or null if it does not.
     *
     * @param array<string,mixed>              $cat
     * @param array<string,array{0:int,1:int}> $relatives
     * @param array<string,array{0:int,1:int}> $spouses
     */
    private function matchFact(Fact $fact, array $cat, array $relatives, array $spouses, Individual $root): ?string
    {
        $record = $fact->record();

        if ($record instanceof Individual) {
            if ($cat['living'] && $record->isDead()) {
                return null;
            }

            return $this->matchIndividual($record, $cat, $relatives, $spouses, $root);
        }

        if ($record instanceof Family) {
            $partners = $record->spouses();

            if ($cat['living'] && ($partners->count() < 2 || $partners->contains(static fn(Individual $i): bool => $i->isDead()))) {
                return null;
            }

            // A couple qualifies if either partner does. Use the closer relationship for the label.
            $best = null;
            foreach ($partners as $partner) {
                $label = $this->matchIndividual($partner, $cat, $relatives, $spouses, $root, true);
                if ($label !== null) {
                    $best ??= $label;
                    if (isset($relatives[$partner->xref()])) {
                        $best = $label;
                        break;
                    }
                }
            }

            return $best;
        }

        return null;
    }

    /**
     * @param array<string,mixed>              $cat
     * @param array<string,array{0:int,1:int}> $relatives
     * @param array<string,array{0:int,1:int}> $spouses
     */
    private function matchIndividual(Individual $individual, array $cat, array $relatives, array $spouses, Individual $root, bool $is_couple = false): ?string
    {
        $xref  = $individual->xref();
        $scope = $cat['scope'];

        // Explicitly named people always qualify (unless the category is off).
        if (in_array($xref, $cat['named'], true)) {
            return isset($relatives[$xref]) ? $this->relationshipLabel($relatives[$xref], $xref === $root->xref()) : I18N::translate('Named');
        }

        if ($scope === self::SCOPE_NAMED) {
            return null;
        }

        $limit = $scope === self::SCOPE_ALL ? PHP_INT_MAX : self::SCOPE_DEGREES[$scope];

        if (isset($relatives[$xref]) && max($relatives[$xref]) <= $limit) {
            return $this->relationshipLabel($relatives[$xref], $xref === $root->xref());
        }

        // Spouses of qualifying relatives. Not needed for couples: the other partner covers it.
        if (!$is_couple && $cat['spouses'] && isset($spouses[$xref]) && max($spouses[$xref]) <= $limit) {
            if ($spouses[$xref] === [0, 0]) {
                return I18N::translate('Your spouse');
            }

            return I18N::translate('Spouse of %s', lcfirst($this->relationshipLabel($spouses[$xref], false)));
        }

        if ($scope === self::SCOPE_ALL) {
            return I18N::translate('Other');
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Relationship calculation
    // ------------------------------------------------------------------

    /**
     * Find blood relatives of $root within $max_degree.
     *
     * Every relative is described by a pair [up, down]:
     *   up   = generations from $root up to the common ancestor
     *   down = generations from that ancestor down to the relative
     * e.g. parent [1,0], sibling [1,1], 1st cousin [2,2], 3rd cousin [4,4].
     * The "degree" used for filtering is max(up, down).
     *
     * @return array<string,array{0:int,1:int}>
     */
    private function findBloodRelatives(Individual $root, int $max_degree): array
    {
        // Step 1: ancestors, with their generation number.
        $ancestors = [$root->xref() => [$root, 0]];
        $queue     = [[$root, 0]];

        while ($queue !== []) {
            [$person, $up] = array_shift($queue);

            if ($up >= $max_degree) {
                continue;
            }

            foreach ($person->childFamilies(Auth::PRIV_HIDE) as $family) {
                foreach ($family->spouses(Auth::PRIV_HIDE) as $parent) {
                    if (!isset($ancestors[$parent->xref()])) {
                        $ancestors[$parent->xref()] = [$parent, $up + 1];
                        $queue[]                    = [$parent, $up + 1];
                    }
                }
            }
        }

        // Step 2: descendants of each ancestor. Closest ancestors first.
        uasort($ancestors, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

        $best = [];

        foreach ($ancestors as [$ancestor, $up]) {
            $queue = [[$ancestor, 0]];

            while ($queue !== []) {
                [$person, $down] = array_shift($queue);
                $xref            = $person->xref();

                // Skip if we already reached this person by an equal or shorter route.
                if (isset($best[$xref]) && $best[$xref][0] <= $up && $best[$xref][1] <= $down) {
                    continue;
                }

                if (!isset($best[$xref]) || $this->isCloser([$up, $down], $best[$xref])) {
                    $best[$xref]         = [$up, $down];
                    $this->people[$xref] = $person;
                }

                if ($down >= $max_degree) {
                    continue;
                }

                foreach ($person->spouseFamilies(Auth::PRIV_HIDE) as $family) {
                    foreach ($family->children(Auth::PRIV_HIDE) as $child) {
                        $queue[] = [$child, $down + 1];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Spouses/partners of blood relatives, keyed by the spouse's xref,
     * holding the [up, down] pair of the relative they are married to.
     *
     * @param array<string,array{0:int,1:int}> $relatives
     *
     * @return array<string,array{0:int,1:int}>
     */
    private function findSpousesOfRelatives(array $relatives): array
    {
        $spouses = [];

        foreach ($relatives as $xref => $pair) {
            $person = $this->people[$xref] ?? null;

            if ($person === null) {
                continue;
            }

            foreach ($person->spouseFamilies(Auth::PRIV_HIDE) as $family) {
                foreach ($family->spouses(Auth::PRIV_HIDE) as $spouse) {
                    $spouse_xref = $spouse->xref();

                    if ($spouse_xref === $xref || isset($relatives[$spouse_xref])) {
                        continue;
                    }

                    if (!isset($spouses[$spouse_xref]) || $this->isCloser($pair, $spouses[$spouse_xref])) {
                        $spouses[$spouse_xref] = $pair;
                    }
                }
            }
        }

        return $spouses;
    }

    /**
     * @param array{0:int,1:int} $a
     * @param array{0:int,1:int} $b
     */
    private function isCloser(array $a, array $b): bool
    {
        return [max($a), $a[0] + $a[1]] < [max($b), $b[0] + $b[1]];
    }

    /**
     * Turn an [up, down] pair into a plain description, e.g. "2nd cousin once removed".
     *
     * @param array{0:int,1:int} $pair
     */
    private function relationshipLabel(array $pair, bool $is_root): string
    {
        [$up, $down] = $pair;

        if ($is_root || ($up === 0 && $down === 0)) {
            return I18N::translate('You');
        }

        // Direct line: children, grandchildren, parents, grandparents.
        if ($up === 0) {
            return $this->grand($down, I18N::translate('Child'), I18N::translate('grandchild'));
        }

        if ($down === 0) {
            return $this->grand($up, I18N::translate('Parent'), I18N::translate('grandparent'));
        }

        // Children of siblings, and siblings of ancestors.
        if ($up === 1 && $down === 1) {
            return I18N::translate('Sibling');
        }

        if ($up === 1) {
            return $this->grand($down - 1, I18N::translate('Niece/nephew'), I18N::translate('grandniece/nephew'));
        }

        if ($down === 1) {
            return $this->grand($up - 1, I18N::translate('Aunt/uncle'), I18N::translate('grandaunt/uncle'));
        }

        // Cousins.
        $removed = abs($up - $down);
        $label   = I18N::translate('%s cousin', $this->ordinal(min($up, $down) - 1));

        if ($removed === 1) {
            $label .= ' ' . I18N::translate('once removed');
        } elseif ($removed === 2) {
            $label .= ' ' . I18N::translate('twice removed');
        } elseif ($removed > 2) {
            $label .= ' ' . I18N::translate('%s times removed', I18N::number($removed));
        }

        return $label;
    }

    /**
     * 1 => "Child", 2 => "Grandchild", 3 => "Great-grandchild", 4 => "Great-great-grandchild"...
     */
    private function grand(int $generations, string $one, string $grand): string
    {
        if ($generations <= 1) {
            return $one;
        }

        if ($generations === 2) {
            return ucfirst($grand);
        }

        return 'Great-' . str_repeat('great-', $generations - 3) . $grand;
    }

    private function ordinal(int $n): string
    {
        $suffix = 'th';
        if ($n % 100 < 11 || $n % 100 > 13) {
            $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';
        }

        return $n . $suffix;
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function loadSettings(int $block_id): array
    {
        $settings = [
            'days'  => (int) $this->getBlockSetting($block_id, 'days', self::DEFAULT_DAYS),
            'today' => (bool) $this->getBlockSetting($block_id, 'today', '1'),
            'root'  => $this->getBlockSetting($block_id, 'root'),
        ];

        foreach (self::CATEGORIES as $key => $tag) {
            $named = $this->getBlockSetting($block_id, $key . '_named');

            $settings[$key] = [
                'scope'   => $this->getBlockSetting($block_id, $key . '_scope', self::DEFAULTS[$key]['scope']),
                'spouses' => (bool) $this->getBlockSetting($block_id, $key . '_spouses', self::DEFAULTS[$key]['spouses']),
                'living'  => (bool) $this->getBlockSetting($block_id, $key . '_living', self::DEFAULTS[$key]['living']),
                'named'   => $named === '' ? [] : explode(',', $named),
            ];
        }

        return $settings;
    }

    /**
     * The person the relationships are measured from: the "Relative to" setting,
     * or else the viewer's own record in this tree.
     */
    private function rootIndividual(Tree $tree, string $root_xref): ?Individual
    {
        if ($root_xref === '') {
            $root_xref = $tree->getUserPreference(Auth::user(), UserInterface::PREF_TREE_ACCOUNT_XREF);
        }

        if ($root_xref === '') {
            return null;
        }

        $individual = Registry::individualFactory()->make($root_xref, $tree);

        return $individual instanceof Individual && $individual->canShow() ? $individual : null;
    }

    public function saveBlockConfiguration(ServerRequestInterface $request, int $block_id): void
    {
        // Note: rules such as isBetween() stick to a Validator object, so use a fresh one for that check.
        $body = Validator::parsedBody($request);

        $this->setBlockSetting($block_id, 'days', (string) Validator::parsedBody($request)->isBetween(1, self::MAX_DAYS)->integer('days'));
        $this->setBlockSetting($block_id, 'today', (string) (int) $body->boolean('today', false));
        $this->setBlockSetting($block_id, 'root', $body->string('root', ''));

        $valid_scopes = array_keys($this->scopeOptions());

        foreach (array_keys(self::CATEGORIES) as $key) {
            $scope = $body->string($key . '_scope', self::DEFAULTS[$key]['scope']);
            if (!in_array($scope, $valid_scopes, true)) {
                $scope = self::DEFAULTS[$key]['scope'];
            }

            $named = array_filter($body->array($key . '_named'), static fn($x): bool => is_string($x) && preg_match('/^[A-Za-z0-9:_.-]+$/', $x) === 1);

            $this->setBlockSetting($block_id, $key . '_scope', $scope);
            $this->setBlockSetting($block_id, $key . '_spouses', (string) (int) $body->boolean($key . '_spouses', false));
            $this->setBlockSetting($block_id, $key . '_living', (string) (int) $body->boolean($key . '_living', false));
            $this->setBlockSetting($block_id, $key . '_named', implode(',', array_unique($named)));
        }
    }

    public function editBlockConfiguration(Tree $tree, int $block_id): string
    {
        $settings = $this->loadSettings($block_id);

        $categories = [];
        foreach (self::CATEGORIES as $key => $tag) {
            $named = [];
            foreach ($settings[$key]['named'] as $xref) {
                $individual = Registry::individualFactory()->make($xref, $tree);
                if ($individual instanceof Individual) {
                    $named[] = $individual;
                }
            }

            $categories[$key] = [
                'title'        => $this->categoryTitle($key),
                'living_label' => $key === 'marr'
                    ? I18N::translate('Only show couples where both partners are living')
                    : I18N::translate('Only show living people'),
                'show_living'  => $key !== 'deat',
                'settings'     => $settings[$key],
                'named'        => $named,
            ];
        }

        $root = $settings['root'] === '' ? null : Registry::individualFactory()->make($settings['root'], $tree);

        return view($this->name() . '::config', [
            'categories'    => $categories,
            'days'          => $settings['days'],
            'today'         => $settings['today'],
            'max_days'      => self::MAX_DAYS,
            'root'          => $root,
            'scope_options' => $this->scopeOptions(),
            'tree'          => $tree,
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function scopeOptions(): array
    {
        return [
            self::SCOPE_OFF   => I18N::translate('Do not show'),
            self::SCOPE_NAMED => I18N::translate('Only the named people below'),
            'immediate'       => I18N::translate('Immediate family (parents, siblings, children)'),
            'close'           => I18N::translate('Close family (up to 1st cousins, grandparents, grandchildren)'),
            'cousin2'         => I18N::translate('Up to 2nd cousins'),
            'cousin3'         => I18N::translate('Up to 3rd cousins'),
            'cousin4'         => I18N::translate('Up to 4th cousins'),
            self::SCOPE_ALL   => I18N::translate('Everyone in the tree'),
        ];
    }

    private function categoryTitle(string $key): string
    {
        return match ($key) {
            'birt'  => I18N::translate('Birthdays'),
            'marr'  => I18N::translate('Wedding anniversaries'),
            'deat'  => I18N::translate('Anniversaries of death'),
            default => $key,
        };
    }
};
