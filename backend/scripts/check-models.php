<?php
// Smoke test: instantiate every Eloquent model and run count() so we
// catch table-name / fillable / cast typos before they hit a real call.

$models = [
    \App\Models\User::class,
    \App\Models\Therapist::class,
    \App\Models\FocusTopic::class,
    \App\Models\Horse::class,
    \App\Models\HorseFocus::class,
    \App\Models\HorseShare::class,
    \App\Models\HorseStat::class,
    \App\Models\TimelineEvent::class,
    \App\Models\Protocol::class,
    \App\Models\ProtocolPhase::class,
    \App\Models\ProtocolPhaseItem::class,
    \App\Models\ProtocolAnalysis::class,
    \App\Models\ProtocolAdvice::class,
    \App\Models\ProtocolTask::class,
    \App\Models\ProtocolTaskCompletion::class,
    \App\Models\Observation::class,
    \App\Models\ObservationPhoto::class,
    \App\Models\Product::class,
    \App\Models\Ingredient::class,
    \App\Models\ScanResult::class,
    \App\Models\ScanIngredient::class,
    \App\Models\LibraryItem::class,
    \App\Models\LibraryChapter::class,
    \App\Models\LibraryArticleSection::class,
    \App\Models\LibraryCategory::class,
    \App\Models\LibraryItemCategory::class,
    \App\Models\LibraryItemFocus::class,
    \App\Models\LibraryBookmark::class,
    \App\Models\LibraryProgress::class,
    \App\Models\SeasonalTip::class,
    \App\Models\CommunityCategory::class,
    \App\Models\CommunityTag::class,
    \App\Models\CommunityPost::class,
    \App\Models\CommunityPostTag::class,
    \App\Models\CommunityReply::class,
    \App\Models\CommunityReaction::class,
    \App\Models\Plan::class,
    \App\Models\PlanBenefit::class,
    \App\Models\Subscription::class,
    \App\Models\Payment::class,
    \App\Models\NotificationPreference::class,
    \App\Models\AccountSetting::class,
    \App\Models\DataExport::class,
    \App\Models\ChatSession::class,
    \App\Models\ChatMessage::class,
    \App\Models\NovaFallbackReply::class,
    \App\Models\IntakeBooking::class,
];

$ok = 0;
$failed = [];
foreach ($models as $class) {
    try {
        $instance = new $class;
        $count = $class::count();
        echo str_pad($class, 50) . " table=" . $instance->getTable() . " count={$count}\n";
        $ok++;
    } catch (\Throwable $e) {
        $failed[] = [$class, $e->getMessage()];
        echo "FAIL {$class}: {$e->getMessage()}\n";
    }
}

echo "\n{$ok}/" . count($models) . " models OK\n";
exit(empty($failed) ? 0 : 1);
