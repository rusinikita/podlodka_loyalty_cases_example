package kafkaconsumer

import (
	"sync"

	"github.com/segmentio/kafka-go"
)

// offsets tracks in-flight messages per partition. Messages of a partition
// are handled by different slots and finish out of order; an offset is
// committed only when every message before it has been handled.
type offsets struct {
	mu    sync.Mutex
	parts map[int]*partitionOffsets
}

type partitionOffsets struct {
	queue []int64
	done  map[int64]bool
}

func newOffsets() *offsets {
	return &offsets{parts: make(map[int]*partitionOffsets)}
}

func (o *offsets) fetched(m kafka.Message) {
	o.mu.Lock()
	defer o.mu.Unlock()

	p, ok := o.parts[m.Partition]
	if !ok {
		p = &partitionOffsets{done: make(map[int64]bool)}
		o.parts[m.Partition] = p
	}
	p.queue = append(p.queue, m.Offset)
}

// done marks m as handled and returns the message whose offset can be
// committed, if the oldest in-flight message of the partition is now done.
func (o *offsets) done(m kafka.Message) (kafka.Message, bool) {
	o.mu.Lock()
	defer o.mu.Unlock()

	p := o.parts[m.Partition]
	p.done[m.Offset] = true

	committed := int64(-1)
	for len(p.queue) > 0 && p.done[p.queue[0]] {
		committed = p.queue[0]
		delete(p.done, committed)
		p.queue = p.queue[1:]
	}
	if committed < 0 {
		return kafka.Message{}, false
	}
	return kafka.Message{Topic: m.Topic, Partition: m.Partition, Offset: committed}, true
}
