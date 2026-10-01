<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useElectionSnapshotUpdates } from '@/composables/useElectionSnapshotUpdates';

interface PartyStanding {
    name: string;
    abbreviation: string;
    color: string | null;
    vote_count: number;
    vote_rate: number;
    won_district_count: number;
    leading_district_count: number;
}

interface ElectionSummary {
    election: {
        year: number;
        captured_at: string | null;
        results_final: boolean;
    };
    parties: PartyStanding[];
}

const DEFAULT_YEAR = 2026;
const PARTY_COUNT = 5;

const election = ref<ElectionSummary['election'] | null>(null);
const parties = ref<PartyStanding[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

let abortController: AbortController | null = null;

const year = computed(() => {
    const params = new URLSearchParams(window.location.search);
    const value = Number(params.get('year'));

    return Number.isInteger(value) && value > 0 ? value : DEFAULT_YEAR;
});

const displayedParties = computed(() =>
    parties.value.slice(0, PARTY_COUNT),
);

function partyVoteRate(party: PartyStanding): string {
    return `${Number(party.vote_rate).toLocaleString('fr-CA', {
        maximumFractionDigits: 1,
    })}%`;
}

function partyVoteCount(party: PartyStanding): string {
    return `${party.vote_count.toLocaleString('fr-CA')} votes`;
}

function partyColor(color: string | null): string {
    if (!color) {
        return '#666666';
    }

    return color.startsWith('#') ? color : `#${color}`;
}

function partyDisplayName(party: PartyStanding): string {
    return party.name;
}

function partyScore(party: PartyStanding): string {
    if (election.value?.results_final) {
        return `${party.won_district_count}`;
    }

    return `${party.won_district_count} / ${party.leading_district_count}`;
}

async function chargerResultats(silent = false): Promise<void> {
    abortController?.abort();

    const controller = new AbortController();

    abortController = controller;

    if (!silent) {
        loading.value = true;
        error.value = null;
    }

    try {
        const response = await fetch(
            `/api/elections/${year.value}/results/summary`,
            {
                headers: {
                    Accept: 'application/json',
                },
                signal: controller.signal,
            },
        );

        if (!response.ok) {
            throw new Error(
                `Unable to load election results: ${response.status}`,
            );
        }

        const data = (await response.json()) as ElectionSummary;

        election.value = data.election;
        parties.value = data.parties;
        error.value = null;
    } catch (exception) {
        if (
            exception instanceof DOMException &&
            exception.name === 'AbortError'
        ) {
            return;
        }

        if (!silent) {
            election.value = null;
            parties.value = [];
            error.value = 'Impossible de charger les résultats.';
        }
    } finally {
        if (!silent && abortController === controller) {
            loading.value = false;
        }
    }
}

onMounted(() => {
    void chargerResultats();
});

onUnmounted(() => {
    abortController?.abort();
    abortController = null;
});

useElectionSnapshotUpdates(() => {
    if (year.value !== 2026) {
        return;
    }

    void chargerResultats(true);
});
</script>

<template>
    <main class="banner-page">
        <div v-if="loading" class="banner-message">
            Chargement…
        </div>

        <div v-else-if="error" class="banner-message banner-error">
            {{ error }}
        </div>

        <section
                v-else
                class="party-results"
                aria-label="Résultats par parti"
        >
            <article
                    v-for="(party, index) in displayedParties"
                    :key="`${party.abbreviation}-${index}`"
                    class="party-card"
                    :class="{ leader: index === 0 }"
                    :style="{ '--party-color': partyColor(party.color) }"
            >
                <div class="party-content">
                    <div class="party-name">
                        {{ partyDisplayName(party) }}
                    </div>

                    <div class="party-footer">
                        <div class="party-votes">
                            <div class="party-vote-rate">
                                {{ partyVoteRate(party) }}
                            </div>

                            <div class="party-vote-count">
                                {{ partyVoteCount(party) }}
                            </div>
                        </div>

                        <div class="party-result">
                            <div class="party-score">
                                {{ partyScore(party) }}
                            </div>

                            <div class="party-score-label">
                                {{ election?.results_final ? 'Élu' : 'Élu / En avance' }}
                            </div>
                        </div>
                    </div>
                </div>
            </article>
        </section>
    </main>
</template>

<style scoped>
:global(html),
:global(body),
:global(#app) {
    width: 1920px;
    height: 360px;
    margin: 0;
    padding: 0;
    overflow: hidden;
}

:global(body) {
    background: transparent;
    font-family:
            Inter,
            ui-sans-serif,
            system-ui,
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            sans-serif;
}

* {
    box-sizing: border-box;
}

.banner-page {
    width: 1920px;
    height: 360px;

    overflow: hidden;
    background: transparent;
    color: #ffffff;
}

.party-results {
    width: 1920px;
    height: 360px;

    display: flex;
    gap: 0;
}

.party-card {
    height: 360px;

    display: flex;
    flex: 1 1 0;

    min-width: 0;

    background: var(--party-color);
    color: #ffffff;
}

.party-card.leader {
    flex: 1.35 1 0;
}

.party-content {
    flex: 1;

    display: flex;
    flex-direction: column;
    justify-content: space-between;

    padding: 22px 24px;
}

.party-name {
    height: 80px;

    display: flex;
    align-items: flex-start;

    font-size: 22px;
    font-weight: 800;

    overflow-wrap: anywhere;

    text-shadow: 0 1px 2px rgb(0 0 0 / 35%);
}

.party-card.leader .party-name {
    font-size: 40px;
    font-weight: 900;
    line-height: 40px;
}

.party-score {
    align-self: flex-end;

    font-size: 48px;
    font-weight: 900;
    line-height: 1;

    text-align: right;

    text-shadow: 0 1px 2px rgb(0 0 0 / 35%);
}

.party-card.leader .party-score {
    font-size: 70px;
}

.party-result {
    align-self: flex-end;

    text-align: right;
}

.party-score {
    font-size: 48px;
    font-weight: 900;
    line-height: 1;
    text-shadow: 0 1px 2px rgb(0 0 0 / 35%);
}

.party-score-label {
    margin-top: 8px;

    font-size: 16px;
    font-weight: 700;
    line-height: 1;

    text-transform: uppercase;
    letter-spacing: 0.4px;

    opacity: 0.9;
}

.party-card.leader .party-score {
    font-size: 58px;
}

.party-card.leader .party-score-label {
    font-size: 18px;
}

.party-footer {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
}

.party-votes {
    min-width: 0;
    text-align: left;
}

.party-vote-rate {
    font-size: 48px;
    font-weight: 900;
    line-height: 0.9;

    text-shadow: 0 1px 2px rgb(0 0 0 / 35%);
}

.party-vote-count {
    margin-top: 6px;

    font-size: 23px;
    font-weight: 500;
    line-height: 1;

    white-space: nowrap;

    text-shadow: 0 1px 2px rgb(0 0 0 / 35%);
}

.party-result {
    flex: 0 0 auto;
    text-align: right;
}

.party-score {
    font-size: 48px;
    font-weight: 900;
    line-height: 0.9;

    text-shadow: 0 1px 2px rgb(0 0 0 / 35%);
}

.party-score-label {
    margin-top: 8px;

    font-size: 16px;
    font-weight: 700;
    line-height: 1;

    text-transform: uppercase;
    letter-spacing: 0.4px;

    opacity: 0.9;
}

.party-card.leader .party-vote-rate,
.party-card.leader .party-score {
    font-size: 58px;
}

.party-card.leader .party-vote-count {
    font-size: 27px;
}

.party-card.leader .party-score-label {
    font-size: 18px;
}
</style>
