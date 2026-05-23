<?php
// Seed one row in each main entity, then exercise a few relationship chains
// to catch FK / pivot / morph wiring bugs.

use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\CommunityReaction;
use App\Models\CommunityReply;
use App\Models\FocusTopic;
use App\Models\Horse;
use App\Models\HorseFocus;
use App\Models\LibraryCategory;
use App\Models\LibraryItem;
use App\Models\Plan;
use App\Models\PlanBenefit;
use App\Models\Protocol;
use App\Models\ProtocolPhase;
use App\Models\ProtocolPhaseItem;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();

$user = User::create([
    'name' => 'Test User',
    'email' => 'rel-test-'.time().'@example.com',
    'password' => 'secret-secret-secret',
]);
$therapist = Therapist::create(['name' => 'Test Therapist']);
$horse = Horse::create(['owner_id' => $user->id, 'name' => 'Thunder']);

$focus = FocusTopic::create(['slug' => 'rel-test-'.time(), 'title' => 'Test', 'order' => 0]);
HorseFocus::create(['horse_id' => $horse->id, 'focus_topic_id' => $focus->id]);

$protocol = Protocol::create(['horse_id' => $horse->id, 'therapist_id' => $therapist->id, 'title' => 'P', 'status' => 'active']);
$phase = ProtocolPhase::create(['protocol_id' => $protocol->id, 'order' => 0, 'title' => 'Phase 1', 'state' => 'active']);
ProtocolPhaseItem::create(['phase_id' => $phase->id, 'order' => 0, 'label' => 'Item A']);

$cat = LibraryCategory::create(['slug' => 'rel-c-'.time(), 'label' => 'Cat']);
$item = LibraryItem::create(['slug' => 'rel-i-'.time(), 'kind' => 'Kruid', 'format' => 'article', 'title' => 'Item']);
$item->categories()->attach($cat);
$item->focusTopics()->attach($focus);

$plan = Plan::create(['slug' => 'rel-p-'.time(), 'name' => 'Plus', 'price_cents' => 1200, 'interval' => 'monthly']);
PlanBenefit::create(['plan_id' => $plan->id, 'label' => 'Unlimited scans', 'order' => 0]);
$sub = Subscription::create([
    'user_id' => $user->id, 'plan_id' => $plan->id,
    'status' => 'active', 'price_cents' => 1200, 'interval' => 'monthly',
]);

$cc = CommunityCategory::create(['slug' => 'rel-cc-'.time(), 'label' => 'Alles']);
$post = CommunityPost::create([
    'author_user_id' => $user->id, 'author_name' => 'Test',
    'body' => 'Hi', 'category_id' => $cc->id,
]);
$reply = CommunityReply::create([
    'post_id' => $post->id, 'author_user_id' => $user->id,
    'author_name' => 'Test', 'body' => 'Hey',
]);
CommunityReaction::create([
    'user_id' => $user->id, 'target_type' => 'post', 'target_id' => $post->id, 'kind' => 'like',
]);
CommunityReaction::create([
    'user_id' => $user->id, 'target_type' => 'reply', 'target_id' => $reply->id, 'kind' => 'like',
]);

// Now traverse the graph.
$checks = [
    'user.horses' => $user->horses->first()?->name === 'Thunder',
    'horse.owner' => $horse->owner->is($user),
    'horse.focusTopics (pivot)' => $horse->focusTopics->first()?->id === $focus->id,
    'horse.activeProtocol' => $horse->activeProtocol->is($protocol),
    'protocol.phases' => $protocol->phases->first()?->title === 'Phase 1',
    'phase.items' => $phase->items->first()?->label === 'Item A',
    'item.categories' => $item->categories->first()?->id === $cat->id,
    'item.focusTopics' => $item->focusTopics->first()?->id === $focus->id,
    'plan.benefits' => $plan->benefits->first()?->label === 'Unlimited scans',
    'subscription.plan' => $sub->plan->is($plan),
    'subscription.user' => $sub->user->is($user),
    'user.subscription' => $user->fresh()->subscription?->is($sub),
    'post.replies' => $post->replies->first()?->is($reply),
    'post.reactions (morph)' => $post->reactions->first()?->kind === 'like',
    'reply.reactions (morph)' => $reply->reactions->first()?->kind === 'like',
    'reaction.target (morphTo)' => CommunityReaction::where('target_type', 'post')->where('target_id', $post->id)->first()?->target?->is($post),
];

DB::rollBack();

$failures = array_filter($checks, fn ($ok) => ! $ok);
foreach ($checks as $label => $ok) {
    echo ($ok ? 'OK ' : 'FAIL ') . $label . "\n";
}
echo "\n" . (count($checks) - count($failures)) . '/' . count($checks) . " relations OK\n";
exit(empty($failures) ? 0 : 1);
