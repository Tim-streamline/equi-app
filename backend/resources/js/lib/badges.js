/** Map a domain status string to a Badge variant. */
export function statusVariant(status) {
    switch (status) {
        case 'active':
        case 'paid':
        case 'confirmed':
        case 'done':
        case 'resolved':
        case 'visible':
        case 'Goed':
        case 'good':
            return 'success';
        case 'paused':
        case 'pending':
        case 'reviewing':
        case 'past_due':
        case 'warn':
        case 'Matig':
        case 'locked':
            return 'warning';
        case 'cancelled':
        case 'failed':
        case 'hidden':
        case 'banned':
        case 'ban':
        case 'danger':
        case 'Slecht':
            return 'destructive';
        case 'pinned':
        case 'completed':
        case 'refunded':
            return 'secondary';
        default:
            return 'muted';
    }
}
