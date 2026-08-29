<?php

namespace SrtValidator;

/**
 * Aligns translation captions with source captions on the timeline.
 *
 * Re-segmenting translation engines (DeepL and others) merge short source
 * captions into fewer translation captions, or split long ones into more.
 * Comparing both lists index-by-index then produces cascades of false
 * defects, so the validator first needs a content-aligned mapping between
 * the two cue lists.
 *
 * The aligner is purely timeline-based (no text comparison), because source
 * and translation do not share a language. It runs two passes:
 *
 * Pass A (anchoring) walks both cue lists in order and matches every
 * translation cue whose start time equals an unconsumed source cue's start
 * time within the tolerance. Re-segmenters keep the surviving cue times
 * exact, so this maps every surviving caption 1:1. Measured on real DeepL
 * output the anchor drift is 0.000 s.
 *
 * Pass B (reconciliation) pairs the leftovers in timeline order:
 *  - an unmatched translation run paired with a timeline-overlapping
 *    unmatched source run becomes drifted pairs (genuine timestamp defects,
 *    e.g. a uniformly shifted block),
 *  - a surplus translation cue nested inside the window of an already
 *    matched source cue becomes a split continuation,
 *  - any other unmatched translation cue becomes an extra cue,
 *  - unmatched source runs become gaps (merged-away or lost content; the
 *    aligner reports the geometry, the validator decides the policy).
 *
 * Events returned (1-based indices are NOT used; callers add +1 for display):
 *  match      source_index, translation_index, start_diff, end_diff
 *  drift      source_index, translation_index, start_diff, end_diff
 *  gap        source_indices[], after_translation_index|null, before_translation_index|null
 *  split_part source_index, translation_index
 *  extra      translation_index
 */
final class CaptionAligner
{
    /**
     * @param list<array{start: float, end: float}> $source
     * @param list<array{start: float, end: float}> $translation
     * @return list<array<string, mixed>>
     */
    public function align(array $source, array $translation, float $tolerance): array
    {
        $sourceCount = count($source);
        $translationCount = count($translation);

        $sourcePartner = array_fill(0, $sourceCount, null);
        $translationPartner = array_fill(0, $translationCount, null);

        // --- Pass A: anchor translation cues on start-time equality --------
        $events = [];
        $nextSource = 0;
        foreach ($translation as $j => $cue) {
            // Sources behind this cue's tolerance window can never anchor a
            // later cue either (both lists are chronological), so skipping
            // them permanently keeps the scan linear.
            while ($nextSource < $sourceCount
                && $source[$nextSource]['start'] < $cue['start'] - $tolerance) {
                $nextSource++;
            }

            if ($nextSource < $sourceCount
                && abs($source[$nextSource]['start'] - $cue['start']) <= $tolerance) {
                $k = $nextSource;
                $sourcePartner[$k] = $j;
                $translationPartner[$j] = $k;
                $events[] = $this->pairEvent('match', $k, $j, $source, $translation);
                $nextSource++;
            }
        }

        // --- Pass B: reconcile the leftovers -------------------------------
        $unmatchedTranslationRuns = $this->contiguousRuns(array_keys(array_filter(
            $translationPartner,
            fn($partner) => $partner === null
        )));

        foreach ($unmatchedTranslationRuns as $run) {
            $spanStart = $translation[$run[0]]['start'];
            $spanEnd = $translation[$run[count($run) - 1]]['end'];

            $sourceRun = $this->findOverlappingSourceRun($source, $sourcePartner, $spanStart, $spanEnd);
            $pairCount = min(count($run), $sourceRun === null ? 0 : count($sourceRun));

            // Positional pairing from the run starts: both lists are
            // chronological, so equal-ish runs line up cue by cue.
            for ($p = 0; $p < $pairCount; $p++) {
                $k = $sourceRun[$p];
                $j = $run[$p];
                $sourcePartner[$k] = $j;
                $translationPartner[$j] = $k;
                $events[] = $this->pairEvent('drift', $k, $j, $source, $translation);
            }

            // Surplus translation cues: continuation of a split source cue,
            // or a translation-only cue.
            for ($p = $pairCount; $p < count($run); $p++) {
                $j = $run[$p];
                $host = $this->findNestedHost($source, $sourcePartner, $translation[$j], $tolerance);
                $events[] = $host !== null
                    ? ['kind' => 'split_part', 'source_index' => $host, 'translation_index' => $j]
                    : ['kind' => 'extra', 'translation_index' => $j];
            }
        }

        // Whatever source cues remain unpaired are gaps.
        foreach ($this->contiguousRuns(array_keys(array_filter(
            $sourcePartner,
            fn($partner) => $partner === null
        ))) as $run) {
            $first = $run[0];
            $last = $run[count($run) - 1];
            $events[] = [
                'kind' => 'gap',
                'source_indices' => $run,
                'after_translation_index' => $first > 0 ? $sourcePartner[$first - 1] : null,
                'before_translation_index' => $last + 1 < $sourceCount ? $sourcePartner[$last + 1] : null,
            ];
        }

        return $events;
    }

    /**
     * @param list<array{start: float, end: float}> $source
     * @param list<array{start: float, end: float}> $translation
     * @return array<string, mixed>
     */
    private function pairEvent(string $kind, int $sourceIndex, int $translationIndex, array $source, array $translation): array
    {
        return [
            'kind' => $kind,
            'source_index' => $sourceIndex,
            'translation_index' => $translationIndex,
            'start_diff' => abs($source[$sourceIndex]['start'] - $translation[$translationIndex]['start']),
            'end_diff' => abs($source[$sourceIndex]['end'] - $translation[$translationIndex]['end']),
        ];
    }

    /**
     * Groups a sorted list of indices into contiguous runs.
     *
     * @param list<int> $indices
     * @return list<list<int>>
     */
    private function contiguousRuns(array $indices): array
    {
        $runs = [];
        $run = [];
        foreach ($indices as $index) {
            if ($run !== [] && $index !== end($run) + 1) {
                $runs[] = $run;
                $run = [];
            }
            $run[] = $index;
        }
        if ($run !== []) {
            $runs[] = $run;
        }
        return $runs;
    }

    /**
     * Finds the contiguous run of unpaired source cues whose time windows
     * overlap the given translation span (the drift case). When several
     * runs overlap, the one with the longest overlap wins.
     *
     * @param list<array{start: float, end: float}> $source
     * @param list<int|null> $sourcePartner
     * @return list<int>|null
     */
    private function findOverlappingSourceRun(array $source, array $sourcePartner, float $spanStart, float $spanEnd): ?array
    {
        $best = null;
        $bestOverlap = 0.0;

        $unpaired = [];
        foreach ($sourcePartner as $k => $partner) {
            if ($partner === null) {
                $unpaired[] = $k;
            }
        }

        foreach ($this->contiguousRuns($unpaired) as $run) {
            $runStart = $source[$run[0]]['start'];
            $runEnd = $source[$run[count($run) - 1]]['end'];
            $overlap = min($runEnd, $spanEnd) - max($runStart, $spanStart);
            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $best = $run;
            }
        }

        return $best;
    }

    /**
     * Finds the already-paired source cue whose window strictly contains the
     * given translation cue's start (the continuation half of a split).
     *
     * @param list<array{start: float, end: float}> $source
     * @param list<int|null> $sourcePartner
     * @param array{start: float, end: float} $cue
     */
    private function findNestedHost(array $source, array $sourcePartner, array $cue, float $tolerance): ?int
    {
        foreach ($sourcePartner as $k => $partner) {
            if ($partner === null) {
                continue;
            }
            if ($cue['start'] > $source[$k]['start'] + $tolerance
                && $cue['start'] < $source[$k]['end']) {
                return $k;
            }
        }
        return null;
    }
}
