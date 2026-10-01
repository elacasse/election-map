<?php

namespace App\Events;

use App\Models\ElectionSnapshot;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ElectionSnapshotCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public ElectionSnapshot $snapshot,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel(
                "elections.{$this->snapshot->election->year}"
            ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'snapshot.created';
    }

    public function broadcastWith(): array
    {
        return [
            'election_year' => $this->snapshot->election->year,
            'snapshot_id'   => $this->snapshot->id,
            'captured_at'   => $this->snapshot->captured_at?->toIso8601String(),
        ];
    }
}
