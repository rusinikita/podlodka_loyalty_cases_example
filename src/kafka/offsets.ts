/**
 * Tracks in-flight messages of a partition batch. Messages of a partition
 * are handled by different slots and finish out of order; an offset is
 * committed only when every message before it has been handled.
 */
export class InflightOffsets {
  private readonly queue: string[];
  private readonly finished = new Set<string>();

  constructor(offsets: string[]) {
    this.queue = [...offsets];
  }

  /**
   * Marks offset as handled and returns the offset that can be committed,
   * if the oldest in-flight message is now done.
   */
  done(offset: string): string | undefined {
    this.finished.add(offset);

    let committed: string | undefined;
    while (this.queue.length > 0 && this.finished.has(this.queue[0]!)) {
      committed = this.queue.shift()!;
      this.finished.delete(committed);
    }
    return committed;
  }
}
