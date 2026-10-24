import { ref } from 'vue';

export const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

/** Index Tab should land on inside a trap of `count` focusable elements; null means let the browser move focus normally. */
export function nextTrapIndex(count, current, backwards) {
    if (count <= 0) return null;
    if (current < 0) return backwards ? count - 1 : 0;
    if (backwards && current === 0) return count - 1;
    if (!backwards && current === count - 1) return 0;
    return null;
}

/** Keeps Tab / Shift+Tab inside `container`. Returns true when it moved focus itself. */
export function trapTab(event, container) {
    if (event.key !== 'Tab' || !container) return false;
    const items = Array.from(container.querySelectorAll(FOCUSABLE));
    const active = container.ownerDocument?.activeElement;
    const target = nextTrapIndex(items.length, items.indexOf(active), event.shiftKey);
    if (target === null) return false;
    event.preventDefault();
    items[target].focus();
    return true;
}

/**
 * One pending confirmation at a time. ask() resolves with { choice } when confirmed (choice is the
 * picked scope value, or null when the dialog offers none) and with null when cancelled, so a caller
 * is `const answer = await ask(...); if (!answer) return;`. Asking again cancels the one still open.
 */
export function useConfirmRisk() {
    const request = ref(null);

    function settle(result) {
        const open = request.value;
        request.value = null;
        open?.resolve(result);
    }

    function ask(config) {
        settle(null);
        return new Promise(resolve => {
            request.value = { ...config, resolve };
        });
    }

    return {
        request,
        ask,
        confirm: choice => settle({ choice: choice ?? null }),
        cancel: () => settle(null),
    };
}

const SWITCH_KEYS = new Set(['mode', 'strategy', 'exchange']);

// Direction of risk for the numeric knobs we know; anything else is "review carefully".
const RISK_UP_WHEN_HIGHER = new Set([
    'size.kelly_cap_pct', 'size.kelly_fraction', 'size.max_open_positions', 'size.max_leverage', 'size.max_slippage_bps',
    'size.allow_one_add_after_pct', 'risk.max_hold_hours', 'risk.trail_giveback_pct', 'risk.trail_activate_pct',
    'perps.max_margin_use_pct', 'perps.initial_margin_pct',
]);
const RISK_UP_WHEN_LOWER = new Set([
    'bank.locked_pct', 'bank.reserve_usd', 'risk.hard_stop_pct', 'risk.volume_ratio_close', 'size.min_ticket_usd',
    'perps.min_liquidation_buffer_pct', 'perps.deleverage_buffer_pct',
]);

const EXPLAIN = {
    'size.kelly_cap_pct': 'The largest share of your book one new position may use. A bigger cap means each trade can lose more.',
    'size.kelly_fraction': 'How much of the strategy\'s estimated edge the desk bets. Higher means bigger bets on the same signal.',
    'size.max_open_positions': 'How many positions can be open at once. More positions means more money exposed at the same time.',
    'size.max_leverage': 'The ceiling on total exposure compared with your equity. Higher leverage magnifies both gains and losses and can lead to liquidation.',
    'size.max_slippage_bps': 'How far from the quoted price an entry may fill. A looser limit accepts worse prices.',
    'size.min_ticket_usd': 'The smallest order the desk will place.',
    'size.allow_one_add_after_pct': 'How far a position must be in profit before the desk adds to it.',
    'bank.locked_pct': 'The share of equity that is never traded. A smaller locked share puts more of your money at risk.',
    'bank.reserve_usd': 'Cash held back for bills. Less reserve means more is available to trade.',
    'risk.hard_stop_pct': 'The loss at which a position is closed no matter what. A wider stop lets a bad trade lose more before it is cut.',
    'risk.max_hold_hours': 'How long a position may stay open before it is closed.',
    'risk.trail_activate_pct': 'The profit at which the trailing stop switches on.',
    'risk.trail_giveback_pct': 'How much profit may be given back from the peak before the trailing stop closes the position.',
    'risk.volume_ratio_close': 'The volume drop that triggers an emergency close.',
    'perps.max_margin_use_pct': 'The share of free margin new entries may use. Higher leaves a thinner cushion against liquidation.',
};

const GROUP_EXPLAIN = {
    size: 'This changes how big your trades are and how many can run at once.',
    risk: 'This changes when the desk cuts a losing position or takes profit.',
    bank: 'This changes how much of your money is allowed to trade.',
    perps: 'This changes margin and leverage on futures, which can lead to liquidation.',
};

const SWITCH_COPY = {
    mode: {
        title: 'Switch operating mode',
        label: 'Operating mode',
        body: to => to === 'live'
            ? ['Live mode sends real orders with real money. Losses are real and cannot be undone.', 'Only continue if you have reviewed the strategy, the sizing and the risk limits.']
            : ['Paper mode stops sending real orders. The desk only watches positions of the active mode, so any live positions that are still open will no longer be managed by stops. Close them first if you want them closed.'],
    },
    strategy: {
        title: 'Switch strategy',
        label: 'Active strategy',
        body: () => ['New entries follow the new strategy\'s rules right away.', 'Existing positions use their recorded opening strategy and saved version for exit rules. Legacy positions with missing or unrecognized strategy information fall back to the active strategy.'],
    },
    exchange: {
        title: 'Switch exchange',
        label: 'Exchange',
        body: () => ['Market data and orders move to the new exchange. Positions on the old exchange are not transferred and the desk will not manage them from the new one.'],
    },
};

/** The key without a per-coin override prefix: "per_product.BTC-USD.size.max_leverage" -> "size.max_leverage". */
export function bareKey(key) {
    return String(key).replace(/^per_product\.[^.]+\./i, '').toLowerCase();
}

export function isRiskyKey(key) {
    const bare = bareKey(key);
    return SWITCH_KEYS.has(bare) || /^(risk|size|bank|perps)\./.test(bare) || /leverage|kelly/.test(bare);
}

function show(value) {
    if (value === undefined || value === null || value === '') return 'not set';
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

function direction(bare, from, to) {
    const a = Number(from);
    const b = Number(to);
    if (!Number.isFinite(a) || !Number.isFinite(b) || a === b) return null;
    if (RISK_UP_WHEN_HIGHER.has(bare)) return b > a ? 'up' : 'down';
    if (RISK_UP_WHEN_LOWER.has(bare)) return b < a ? 'up' : 'down';
    return null;
}

/**
 * ConfirmRisk request for changing one setting, or null when the change needs no confirmation
 * (not a risk key, or nothing actually changes). `to` may be the string "default" for removing an override.
 */
export function describeSettingChange(key, from, to) {
    const bare = bareKey(key);
    if (!isRiskyKey(key) || String(from) === String(to)) return null;

    const switchCopy = SWITCH_COPY[bare];
    if (switchCopy) {
        return {
            title: switchCopy.title,
            body: switchCopy.body(String(to)),
            changes: [{ label: switchCopy.label, from: show(from), to: show(to) }],
            confirmLabel: bare === 'mode' && to === 'live' ? 'Continue to live' : 'Switch',
            danger: bare === 'mode' && to === 'live',
        };
    }

    const body = [EXPLAIN[bare] ?? GROUP_EXPLAIN[bare.split('.')[0]] ?? 'This is a risk setting. Review the change before applying it.'];
    const dir = direction(bare, from, to);
    if (dir === 'up') body.push('This change makes the desk riskier.');
    if (dir === 'down') body.push('This change makes the desk more cautious.');
    return {
        title: 'Change a risk setting',
        body,
        changes: [{ label: key, from: show(from), to: show(to) }],
        confirmLabel: 'Apply change',
        danger: dir === 'up',
    };
}

/** ConfirmRisk request for the paper reset: the dialog's choice is the API scope (main | all). */
export function describePaperReset() {
    return {
        title: 'Reset the paper book',
        body: [
            'This permanently deletes paper positions and paper cash history. It cannot be undone.',
            'Choose what to reset. Arena seats are separate practice accounts that each trade on their own.',
        ],
        changes: [{ label: 'Paper book', from: 'positions, trades and cash', to: 'empty, starting cash redeposited' }],
        choices: [
            { value: 'main', label: 'Main desk only', description: 'Arena seats keep their positions and cash.' },
            { value: 'all', label: 'Main desk and all arena seats', description: 'Every seat is wiped too and restarts from its own starting cash.' },
        ],
        confirmLabel: 'Reset',
        danger: true,
    };
}
