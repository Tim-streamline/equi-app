export function loginDestination(search: string): string {
  const params = new URLSearchParams(search);
  const returnTo = params.get('returnTo');
  if (returnTo?.startsWith('/')) {
    const target = new URL(returnTo, 'https://equi-app.invalid');
    if (target.origin === 'https://equi-app.invalid' && !target.pathname.startsWith('/onboarding')) {
      return target.pathname + target.search + target.hash;
    }
  }
  const post = params.get('communityPost');
  return post && /^[0-9a-f-]{36}$/i.test(post) ? `/community/thread/${post}` : '/home';
}
