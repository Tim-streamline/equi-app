import type { FeedSubmission } from './feed-scanner-api';

export type PhotoStep = 'composition' | 'analysis' | 'front';

export function unreadableComposition(submission: FeedSubmission | null): boolean {
  const recognition = submission?.recognition;
  // An uncertain identity or an unavailable OCR service is not a blurry photo.
  return recognition?.decision === 'unreadable' || (recognition?.ocr?.engine === 'tesseract-local-1' && recognition.ocr.words === 0);
}

export function resumePhotoStep(submission: FeedSubmission | null): PhotoStep {
  if (unreadableComposition(submission) || !needsAdditionalPhotos(submission)) return 'composition';
  return submission?.photo_ids?.analysis ? 'front' : 'analysis';
}

export function needsAdditionalPhotos(submission: FeedSubmission | null): boolean {
  return !!submission && (submission.workflow_version === 1 || !!submission.product_id || !!submission.product_version_id || submission.status === 'needs_photos');
}

export function requiredPhotoSlots(submission: FeedSubmission | null): string[] {
  return needsAdditionalPhotos(submission) ? (submission?.workflow_version === 1 ? ['composition', 'analysis'] : ['composition', 'analysis', 'front']) : ['composition'];
}

export function missingPhotoCount(submission: FeedSubmission | null, photos: Record<string, unknown>): number {
  return requiredPhotoSlots(submission).filter(slot => !photos[slot] && !submission?.photo_ids?.[slot]).length;
}
