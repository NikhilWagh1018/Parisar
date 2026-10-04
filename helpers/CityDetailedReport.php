<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/CityDetailedReport.php
//  Pure rules for the detailed city audit report (one section per
//  road, same layout as the per-road report). No database access.
//  Scores are penalties: 0 is perfect, 100 is worst. Lower is better.
// ═══════════════════════════════════════════════════════════════

if (!function_exists('cityRoadAnalysis')) {
    /**
     * Summary, issues and recommendations for one road.
     *
     * @param list<array<string,mixed>> $rows    Segments of the road. Scored (approved)
     *                                           rows carry final, safety_score,
     *                                           continuity_score, comfort_score, rating.
     * @param array<int,array<string,mixed>> $details  audit_id => detail (see
     *                                           AuditReportRepository::detailsForAuditIds)
     * @return array<string,mixed>
     */
    function cityRoadAnalysis(array $rows, array $details): array
    {
        $scored = [];
        foreach ($rows as $r) {
            if (isset($r['final'])) {
                $scored[] = $r;
            }
        }

        $avg = ['safety' => 0.0, 'continuity' => 0.0, 'comfort' => 0.0];
        if ($scored) {
            foreach ($scored as $r) {
                $avg['safety']     += (float)$r['safety_score'];
                $avg['continuity'] += (float)$r['continuity_score'];
                $avg['comfort']    += (float)$r['comfort_score'];
            }
            foreach ($avg as $k => $v) {
                $avg[$k] = round($v / count($scored), 1);
            }
        }

        $observations = []; $critical = []; $missingTrack = [];
        $obsTotal = 0; $noRamps = 0; $surfaceIssues = false; $auditedLength = 0.0;
        $best = null; $worst = null;

        foreach ($scored as $r) {
            $auditedLength += (float)$r['length'];
            if ($best === null || (float)$r['final'] < (float)$best['final'])   { $best  = $r; }
            if ($worst === null || (float)$r['final'] > (float)$worst['final']) { $worst = $r; }

            $d = $details[(int)($r['latest_audit_id'] ?? 0)] ?? null;
            if ($d === null) {
                continue;
            }
            $n   = (int)$r['segment_number'];
            $a   = $d['audit'];
            $obsTotal += (int)$d['obs_total'];
            $noRamps  += (int)$d['no_ramps'];

            $surf = json_decode((string)($a['surface_issues'] ?? '[]'), true);
            if (is_array($surf) && $surf) {
                $surfaceIssues = true;
            }
            if ((int)$d['obs_total'] > 10) {
                $observations[] = ['type' => 'warn', 'text' => "Segment $n: High obstruction count ({$d['obs_total']} total)"];
            }
            if (($a['cycle_track_missing'] ?? '') === 'Yes') {
                $missing = (float)($a['missing_length'] ?? 0);
                $missingTrack[] = $n;
                $observations[] = ['type' => 'bad', 'text' => "Segment $n: Cycle track section missing" . ($missing > 0 ? " ({$missing}m)" : '')];
                $critical[]     = "Missing cycle track in Segment $n";
            }
            if ((int)$d['no_ramps'] > 0) {
                $observations[] = ['type' => 'warn', 'text' => "Segment $n: {$d['no_ramps']} intersection(s) missing ramps"];
            }
            if ((int)$d['no_sign'] > 0) {
                $observations[] = ['type' => 'warn', 'text' => "Segment $n: {$d['no_sign']} intersection(s) missing markings/signage"];
            }
        }

        // Penalty scores: a HIGH dimension score is the problem.
        $rec = [];
        if ($obsTotal > 0)        { $rec[] = 'Conduct regular maintenance and remove all obstructions from the cycle track.'; }
        if ($noRamps > 0)         { $rec[] = 'Build on/off ramps at all intersection points for smooth cyclist transitions.'; }
        if ($missingTrack)        { $rec[] = 'Construct missing cycle track sections to restore full network continuity.'; }
        if ($surfaceIssues)       { $rec[] = 'Repair damaged or uneven surface sections to improve cycling comfort.'; }
        if ($scored && $avg['safety'] > 50)     { $rec[] = 'Install buffer zones or physical separators between cycle track and motorised traffic.'; }
        if ($scored && $avg['continuity'] > 35) { $rec[] = 'Add clear markings and signage throughout the cycle track for better visibility.'; }
        if ($scored && $avg['comfort'] > 50)    { $rec[] = 'Enforce no-encroachment rules and remove parked vehicles or vendors from the cycle track.'; }
        if ($scored && $avg['safety'] > 60)     { $rec[] = 'Improve after-sunset lighting along the full length of the cycle track.'; }

        $weak = null;
        if ($scored) {
            $weak = array_search(max($avg), $avg, true);
        }

        return [
            'scored'        => count($scored),
            'avg'           => $avg,
            'weak'          => $weak === false ? null : $weak,
            'best'          => $best,
            'worst'         => $worst,
            'audited_len'   => $auditedLength,
            'observations'  => $observations,
            'critical'      => $critical,
            'recommend'     => $rec,
        ];
    }
}

if (!function_exists('cityDetailValue')) {
    /** [display text, css class] for a detail-card value. */
    function cityDetailValue($v): array
    {
        if ($v === null || $v === '' || strtolower((string)$v) === 'n/a') {
            return ['N/A', 'na'];
        }
        $l = strtolower((string)$v);
        if ($l === 'yes') { return ['Yes', 'yes']; }
        if ($l === 'no')  { return ['No', 'no']; }
        return [(string)$v, ''];
    }
}
