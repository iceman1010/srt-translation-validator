<?php

use PHPUnit\Framework\TestCase;
use SrtValidator\CaptionAligner;

class CaptionAlignerTest extends TestCase
{
    private const TOLERANCE = 0.5;

    /** @param list<array{start: float, end: float}> $starts */
    private function cues(array $starts): array
    {
        $cues = [];
        foreach ($starts as [$start, $end]) {
            $cues[] = ['start' => $start, 'end' => $end];
        }
        return $cues;
    }

    private function align(array $source, array $translation): array
    {
        return (new CaptionAligner())->align($source, $translation, self::TOLERANCE);
    }

    public function testPerfectAlignment(): void
    {
        $source = $this->cues([[0, 1.5], [2, 3.5], [4, 5.5]]);
        $events = $this->align($source, $source);

        $this->assertCount(3, $events);
        foreach ($events as $i => $event) {
            $this->assertSame('match', $event['kind']);
            $this->assertSame($i, $event['source_index']);
            $this->assertSame($i, $event['translation_index']);
            $this->assertEqualsWithDelta(0.0, $event['start_diff'], 1e-9);
            $this->assertEqualsWithDelta(0.0, $event['end_diff'], 1e-9);
        }
    }

    public function testMergedSourceCueBecomesInteriorGap(): void
    {
        // DeepL pattern: the translation drops one cue and anchors the
        // merged text on the following cue's exact times.
        $source = $this->cues([[0, 1.5], [2, 3.5], [4, 5.5], [6, 7.5]]);
        $translation = $this->cues([[0, 1.5], [4, 5.5], [6, 7.5]]);

        $events = $this->align($source, $translation);

        $matches = array_filter($events, fn($e) => $e['kind'] === 'match');
        $gaps = array_filter($events, fn($e) => $e['kind'] === 'gap');

        $this->assertCount(3, $matches);
        $this->assertCount(1, $gaps);

        $gap = array_values($gaps)[0];
        $this->assertSame([1], $gap['source_indices']);
        $this->assertSame(0, $gap['after_translation_index']);
        $this->assertSame(1, $gap['before_translation_index']);
    }

    public function testShiftedBlockPairsAsDrift(): void
    {
        // Cues 3-5 are shifted by +0.8s: no start falls inside another
        // source's tolerance window, so the block must be reconciled
        // positionally as drifted pairs, not as gaps.
        $source = $this->cues([[0, 1.5], [2, 3.5], [4, 5.5], [6, 7.5], [8, 9.5]]);
        $translation = $this->cues([[0, 1.5], [2, 3.5], [4.8, 6.3], [6.8, 8.3], [8.8, 10.3]]);

        $events = $this->align($source, $translation);

        $this->assertCount(2, array_filter($events, fn($e) => $e['kind'] === 'match'));
        $drifts = array_values(array_filter($events, fn($e) => $e['kind'] === 'drift'));
        $this->assertCount(3, $drifts);

        foreach ($drifts as $i => $event) {
            $this->assertSame($i + 2, $event['source_index']);
            $this->assertSame($i + 2, $event['translation_index']);
            $this->assertEqualsWithDelta(0.8, $event['start_diff'], 1e-9);
        }

        $this->assertCount(0, array_filter($events, fn($e) => $e['kind'] === 'gap'));
    }

    public function testSplitContinuation(): void
    {
        // One long source cue split into two translation cues: the second
        // half has no source start of its own and must be attributed as a
        // split continuation of the first half's source.
        $source = $this->cues([[0, 4.0], [6, 8.0]]);
        $translation = $this->cues([[0, 2.0], [2.2, 4.0], [6, 8.0]]);

        $events = $this->align($source, $translation);

        $this->assertCount(2, array_filter($events, fn($e) => $e['kind'] === 'match'));

        $splits = array_values(array_filter($events, fn($e) => $e['kind'] === 'split_part'));
        $this->assertCount(1, $splits);
        $this->assertSame(0, $splits[0]['source_index']);
        $this->assertSame(1, $splits[0]['translation_index']);
    }

    public function testTranslationOnlyCueBecomesExtra(): void
    {
        $source = $this->cues([[0, 1.5], [4, 5.5]]);
        $translation = $this->cues([[0, 1.5], [4, 5.5], [8, 9.5]]);

        $events = $this->align($source, $translation);

        $extras = array_values(array_filter($events, fn($e) => $e['kind'] === 'extra'));
        $this->assertCount(1, $extras);
        $this->assertSame(2, $extras[0]['translation_index']);
    }

    public function testLeadingGapIsNotInterior(): void
    {
        // The translation starts one cue late: the skipped source cue has
        // no anchor before it, so the gap must not be mistaken for a merge.
        $source = $this->cues([[0, 1.5], [2, 3.5], [4, 5.5]]);
        $translation = $this->cues([[2, 3.5], [4, 5.5]]);

        $events = $this->align($source, $translation);

        $gaps = array_values(array_filter($events, fn($e) => $e['kind'] === 'gap'));
        $this->assertCount(1, $gaps);
        $this->assertSame([0], $gaps[0]['source_indices']);
        $this->assertNull($gaps[0]['after_translation_index']);
        $this->assertSame(0, $gaps[0]['before_translation_index']);
    }

    public function testEdgeGapsAfterReconciliationStayGaps(): void
    {
        // Two separate gaps: one interior (merge candidate), one trailing
        // (real loss candidate). Reconciliation must keep both.
        $source = $this->cues([[0, 1.5], [2, 3.5], [4, 5.5], [6, 7.5]]);
        $translation = $this->cues([[0, 1.5], [4, 5.5]]);

        $events = $this->align($source, $translation);

        $gaps = array_values(array_filter($events, fn($e) => $e['kind'] === 'gap'));
        $this->assertCount(2, $gaps);
        $this->assertSame([1], $gaps[0]['source_indices']);
        $this->assertNotNull($gaps[0]['after_translation_index']);
        $this->assertNotNull($gaps[0]['before_translation_index']);
        $this->assertSame([3], $gaps[1]['source_indices']);
        $this->assertNull($gaps[1]['before_translation_index']);
    }
}
