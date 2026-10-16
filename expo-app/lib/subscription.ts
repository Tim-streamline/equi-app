/** Match the server's single subscription rule, including while offline. */
export function subscriptionActive(subscription: Record<string, any>, now = new Date()): boolean {
  const time = now.getTime();
  const timestamp = (value: string) => new Date(/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d(?:\.\d+)?$/.test(value) ? value.replace(' ', 'T') + 'Z' : value).getTime();
  return subscription.status === 'active'
    && (!subscription.started_at || timestamp(subscription.started_at) <= time)
    && ['paid_through', 'ended_at', 'cancelled_at'].every(key => !subscription[key] || timestamp(subscription[key]) > time);
}
