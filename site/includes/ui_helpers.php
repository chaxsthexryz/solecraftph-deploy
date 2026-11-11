<?php
/**
 * Small view helpers shared by customer + admin pages so status
 * pills and star ratings render consistently everywhere.
 */

function status_badge_class(string $status): string
{
    $map = [
        'pending'    => 'warn',
        'processing' => 'warn',
        'shipped'    => 'muted',
        'completed'  => 'ok',
        'refunded'   => 'ok',
        'approved'   => 'ok',
        'active'     => 'ok',
        'cancelled'  => 'bad',
        'rejected'   => 'bad',
        'suspended'  => 'bad',
        'requested'  => 'warn',
        'new'        => 'warn',
        'read'       => 'muted',
        'resolved'   => 'ok',
        'out'        => 'bad',
    ];
    return $map[$status] ?? 'muted';
}

function render_status_badge(string $status): string
{
    $cls = status_badge_class($status);
    return '<span class="badge-status badge-status--' . $cls . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
}

function render_stars(float $average, ?int $count = null, string $size = ''): string
{
    $full = (int) round($average);
    $full = max(0, min(5, $full));
    $stars = str_repeat('★', $full) . str_repeat('☆', 5 - $full);
    $cls = 'stars' . ($size ? ' stars--' . $size : '');
    $html = '<span class="' . $cls . '">' . $stars . '</span>';
    if ($count !== null) {
        $html .= ' <span class="mono">' . number_format($average, 1) . ' (' . $count . ' review' . ($count === 1 ? '' : 's') . ')</span>';
    }
    return $html;
}
