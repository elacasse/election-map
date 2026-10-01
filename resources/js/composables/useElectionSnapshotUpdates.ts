import { useEchoPublic } from '@laravel/echo-vue';

interface ElectionSnapshotCreatedEvent {
    election_year: number;
    snapshot_id: number;
    captured_at: string | null;
}

export function useElectionSnapshotUpdates(
    onSnapshotCreated: (event: ElectionSnapshotCreatedEvent) => void,
) {
    return useEchoPublic<ElectionSnapshotCreatedEvent>(
        'elections.2026',
        '.snapshot.created',
        onSnapshotCreated,
    );
}
