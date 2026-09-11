<script setup>
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import { api, fmt } from '../api';
import ContestsPanel from '../components/ContestsPanel.vue';

const activeTab = ref('archive'); // 'archive' | 'contests'

// --- Typeahead ---
const query = ref('');
const typeaheadResults = ref([]);
const typeaheadOpen = ref(false);
let typeaheadTimer = null;

function onQueryInput() {
    typeaheadOpen.value = false;
    clearTimeout(typeaheadTimer);
    const q = query.value.trim();
    if (q.length < 2) {
        typeaheadResults.value = [];
        return;
    }
    typeaheadTimer = setTimeout(async () => {
        try {
            const res = await api.get(`/hub/strategies/typeahead?q=${encodeURIComponent(q)}`);
            typeaheadResults.value = res.results || [];
            typeaheadOpen.value = true;
        } catch (e) {
            typeaheadResults.value = [];
        }
    }, 250);
}

function pickTypeahead(hit) {
    typeaheadOpen.value = false;
    query.value = hit.name || hit.slug;
    openDetail(hit.slug);
}

// --- Filters / search ---
const filters = reactive({
    exchange: '',
    timeframe: '',
    asset: '',
    min_win_rate: '',
    max_drawdown: '',
    author: '',
    tag: '',
    sort: 'new',
});
const results = ref([]);
const total = ref(0);
const page = ref(1);
const searching = ref(false);
const searchError = ref('');
let searchTimer = null;

function scheduleSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(runSearch, 350);
}

async function runSearch(resetPage = true) {
    if (resetPage) page.value = 1;
    searching.value = true;
    searchError.value = '';
    try {
        const params = new URLSearchParams();
        if (query.value.trim()) params.set('q', query.value.trim());
        for (const key of ['exchange', 'timeframe', 'asset', 'author', 'tag', 'sort']) {
            if (filters[key]) params.set(key, filters[key]);
        }
        if (filters.min_win_rate !== '') params.set('min_win_rate', filters.min_win_rate);
        if (filters.max_drawdown !== '') params.set('max_drawdown', filters.max_drawdown);
        params.set('page', String(page.value));
        const res = await api.get(`/hub/strategies/search?${params.toString()}`);
        results.value = res.results || [];
        total.value = res.total ?? results.value.length;
    } catch (e) {
        searchError.value = e.message || 'Search failed.';
        results.value = [];
    } finally {
        searching.value = false;
    }
}

// --- Detail drawer ---
const detail = ref(null);
const detailSlug = ref(null);
const detailLoading = ref(false);
const detailError = ref('');
const comments = ref([]);
const commentsLoading = ref(false);
const selectedVersion = ref(null);
const importBusy = ref(false);
const importResult = ref(null);
const importError = ref('');

async function openDetail(slug) {
    detailSlug.value = slug;
    detail.value = null;
    detailError.value = '';
    detailLoading.value = true;
    importResult.value = null;
    importError.value = '';
    comments.value = [];
    try {
        const res = await api.get(`/hub/strategies/${encodeURIComponent(slug)}`);
        detail.value = res;
        selectedVersion.value = res.current_version?.version ?? res.current_version ?? null;
        loadComments(slug);
    } catch (e) {
        detailError.value = e.message || 'Failed to load strategy.';
    } finally {
        detailLoading.value = false;
    }
}

async function loadComments(slug) {
    commentsLoading.value = true;
    try {
        const res = await api.get(`/hub/strategies/${encodeURIComponent(slug)}/comments`);
        comments.value = res.comments || [];
    } catch (e) {
        // read-only panel — a failed comment fetch shouldn't block the rest of the drawer
        comments.value = [];
    } finally {
        commentsLoading.value = false;
    }
}

function closeDetail() {
    detailSlug.value = null;
    detail.value = null;
}

async function doImport() {
    if (!detailSlug.value || !selectedVersion.value) return;
    importBusy.value = true;
    importError.value = '';
    importResult.value = null;
    try {
        const res = await api.post('/hub/import', {
            slug: detailSlug.value,
            version: selectedVersion.value,
        });
        importResult.value = res.plugin;
    } catch (e) {
        importError.value = e.message || 'Import failed.';
    } finally {
        importBusy.value = false;
    }
}

function statValue(stats, key) {
    if (!stats || stats[key] === undefined || stats[key] === null) return '—';
    return stats[key];
}

function onDocClick(event) {
    if (!event.target.closest('.archive-typeahead')) typeaheadOpen.value = false;
}
onMounted(() => {
    document.addEventListener('click', onDocClick);
    runSearch();
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    clearTimeout(typeaheadTimer);
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="archive-page">
        <h1>Hub Archive</h1>
        <p class="sub">Browse strategies published by the community. Search, inspect, and import — no hub login required.</p>

        <div class="tabs">
            <button :class="{ active: activeTab === 'archive' }" @click="activeTab = 'archive'">Archive</button>
            <button :class="{ active: activeTab === 'contests' }" @click="activeTab = 'contests'">Contests</button>
        </div>

        <section v-if="activeTab === 'archive'" class="archive-body">
            <div class="archive-typeahead">
                <input
                    v-model="query"
                    type="text"
                    placeholder="Search strategies by name or slug…"
                    @input="onQueryInput"
                    @keyup.enter="runSearch()"
                    @focus="typeaheadOpen = typeaheadResults.length > 0"
                />
                <ul v-if="typeaheadOpen && typeaheadResults.length" class="typeahead-list">
                    <li v-for="hit in typeaheadResults" :key="hit.slug" @click="pickTypeahead(hit)">
                        <strong>{{ hit.name || hit.slug }}</strong>
                        <span class="muted">{{ hit.slug }}</span>
                        <span class="muted" v-if="hit.author">by {{ hit.author }}</span>
                    </li>
                </ul>
            </div>

            <div class="filters">
                <input v-model="filters.exchange" placeholder="Exchange" @input="scheduleSearch" />
                <input v-model="filters.timeframe" placeholder="Timeframe" @input="scheduleSearch" />
                <input v-model="filters.asset" placeholder="Asset" @input="scheduleSearch" />
                <input v-model="filters.min_win_rate" type="number" step="0.01" placeholder="Min win rate" @input="scheduleSearch" />
                <input v-model="filters.max_drawdown" type="number" step="0.01" placeholder="Max drawdown" @input="scheduleSearch" />
                <input v-model="filters.author" placeholder="Author" @input="scheduleSearch" />
                <input v-model="filters.tag" placeholder="Tag" @input="scheduleSearch" />
                <select v-model="filters.sort" @change="scheduleSearch">
                    <option value="new">Newest</option>
                    <option value="stars">Most starred</option>
                    <option value="imports">Most imported</option>
                    <option value="win_rate">Win rate</option>
                </select>
                <button :disabled="searching" @click="runSearch()">Search</button>
            </div>

            <p v-if="searchError" class="bad">{{ searchError }}</p>
            <p v-if="searching" class="muted">Searching…</p>

            <table class="results-table" v-if="results.length">
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Name</th>
                        <th>Author</th>
                        <th>Tags</th>
                        <th>Timeframe</th>
                        <th>Win rate</th>
                        <th>Drawdown</th>
                        <th>Trades</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in results" :key="row.slug" class="clickable" @click="openDetail(row.slug)">
                        <td>{{ row.slug }}</td>
                        <td>{{ row.name }}</td>
                        <td>{{ row.author }}</td>
                        <td>{{ (row.tags || []).join(', ') }}</td>
                        <td>{{ row.timeframe || '—' }}</td>
                        <td>{{ statValue(row.stats, 'win_rate') }}</td>
                        <td>{{ statValue(row.stats, 'drawdown') }}</td>
                        <td>{{ statValue(row.stats, 'trades') }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else-if="!searching && !searchError" class="muted">No results yet.</p>
            <p v-if="total" class="muted small">{{ total }} total match(es) — page {{ page }}</p>

            <div v-if="detailSlug" class="drawer">
                <div class="drawer-header">
                    <h2>{{ detail?.strategy?.name || detailSlug }}</h2>
                    <button class="close" @click="closeDetail">✕</button>
                </div>
                <p v-if="detailLoading" class="muted">Loading…</p>
                <p v-if="detailError" class="bad">{{ detailError }}</p>

                <div v-if="detail">
                    <p class="muted">
                        by {{ detail.author?.handle || detail.author }}
                        · stars {{ detail.stats?.stars ?? '—' }}
                        · imports {{ detail.stats?.imports ?? '—' }}
                        · comments {{ detail.stats?.comments ?? '—' }}
                    </p>

                    <h3>Versions</h3>
                    <ul class="versions-list">
                        <li v-for="v in detail.versions || []" :key="v.version">
                            <label>
                                <input type="radio" :value="v.version" v-model="selectedVersion" />
                                v{{ v.version }} — {{ v.changelog || 'no changelog' }}
                                <span class="muted">({{ fmt.time(v.created_at) }})</span>
                            </label>
                        </li>
                        <li v-if="!(detail.versions || []).length" class="muted">No versions listed.</li>
                    </ul>

                    <div class="row">
                        <button :disabled="importBusy || !selectedVersion" @click="doImport">
                            {{ importBusy ? 'Importing…' : `Import v${selectedVersion || ''}` }}
                        </button>
                        <span v-if="importResult" class="ok">
                            Imported as plugin #{{ importResult.id }} —
                            <router-link to="/builder">open it in the Builder</router-link>
                        </span>
                        <span v-if="importError" class="bad">{{ importError }}</span>
                    </div>

                    <h3>Comments</h3>
                    <p v-if="commentsLoading" class="muted">Loading comments…</p>
                    <ul class="comments-list" v-else-if="comments.length">
                        <li v-for="c in comments" :key="c.id">
                            <strong>{{ c.author }}</strong>
                            <span class="muted">{{ fmt.time(c.created_at) }}</span>
                            <p>{{ c.body }}</p>
                        </li>
                    </ul>
                    <p v-else class="muted">No comments yet.</p>
                </div>
            </div>
        </section>

        <section v-else class="contests-body">
            <ContestsPanel />
        </section>
    </div>
</template>

<style scoped>
.archive-page { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
.sub { opacity: 0.8; }
.tabs { display: flex; gap: 0.5rem; margin: 1rem 0; }
.tabs button { padding: 0.4rem 1rem; border: 1px solid #444; background: transparent; cursor: pointer; border-radius: 4px; }
.tabs button.active { background: #2a2a2a; font-weight: 600; }
.archive-typeahead { position: relative; margin-bottom: 1rem; }
.archive-typeahead input { width: 100%; padding: 0.5rem; font-size: 1rem; }
.typeahead-list { position: absolute; top: 100%; left: 0; right: 0; z-index: 10; background: #1c1c1c; border: 1px solid #444; max-height: 260px; overflow-y: auto; margin: 0; padding: 0; list-style: none; }
.typeahead-list li { padding: 0.5rem 0.75rem; cursor: pointer; display: flex; gap: 0.5rem; align-items: baseline; }
.typeahead-list li:hover { background: #2a2a2a; }
.filters { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
.filters input, .filters select { padding: 0.35rem 0.5rem; }
.filters input[type="number"] { width: 8rem; }
.results-table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
.results-table th, .results-table td { padding: 0.4rem 0.6rem; border-bottom: 1px solid #333; text-align: left; }
.results-table tr.clickable { cursor: pointer; }
.results-table tr.clickable:hover { background: #232323; }
.muted { opacity: 0.65; }
.muted.small { font-size: 0.85rem; }
.ok { color: #4caf50; }
.bad { color: #e05555; }
.drawer { margin-top: 1.5rem; padding: 1rem; border: 1px solid #444; border-radius: 6px; background: #191919; }
.drawer-header { display: flex; justify-content: space-between; align-items: center; }
.drawer-header .close { background: transparent; border: none; font-size: 1.1rem; cursor: pointer; }
.versions-list, .comments-list { list-style: none; padding: 0; margin: 0.5rem 0; }
.versions-list li, .comments-list li { padding: 0.35rem 0; border-bottom: 1px solid #2a2a2a; }
.row { display: flex; gap: 0.75rem; align-items: center; margin: 0.75rem 0; }
.contests-placeholder { padding: 2rem 0; opacity: 0.7; }
</style>
