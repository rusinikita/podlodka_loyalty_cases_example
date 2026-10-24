from collections import deque


class InflightOffsets:
    """Tracks in-flight messages per partition. Messages of a partition are
    handled by different slots and finish out of order; an offset is committed
    only when every message before it has been handled."""

    def __init__(self) -> None:
        self._queues: dict[int, deque[int]] = {}
        self._done: dict[int, set[int]] = {}

    def fetched(self, partition: int, offset: int) -> None:
        self._queues.setdefault(partition, deque()).append(offset)
        self._done.setdefault(partition, set())

    def done(self, partition: int, offset: int) -> int | None:
        """Marks the message as handled and returns the offset that can be
        committed, if the oldest in-flight message of the partition is now done."""
        queue, done = self._queues[partition], self._done[partition]
        done.add(offset)

        committed = None
        while queue and queue[0] in done:
            committed = queue.popleft()
            done.discard(committed)
        return committed
