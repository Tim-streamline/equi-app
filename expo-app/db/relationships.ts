// Wire up TinyBase Relationships so screens can do useLocalRowIds, useRemoteRowId, etc.

import type { Relationships } from 'tinybase';

export function setupRelationships(rel: Relationships) {
  // horse → owner
  rel.setRelationshipDefinition('horseOwner', 'horses', 'users', 'ownerId');

  // horseFocus join
  rel.setRelationshipDefinition('focusForHorse', 'horseFocus', 'horses', 'horseId');
  rel.setRelationshipDefinition('focusTopicOfFocus', 'horseFocus', 'focusTopics', 'focusTopicId');

  // shares
  rel.setRelationshipDefinition('sharesForHorse', 'horseShares', 'horses', 'horseId');

  // horse stats / observations / timeline
  rel.setRelationshipDefinition('statsForHorse', 'horseStats', 'horses', 'horseId');
  rel.setRelationshipDefinition('observationsForHorse', 'observations', 'horses', 'horseId');
  rel.setRelationshipDefinition('timelineForHorse', 'timelineEvents', 'horses', 'horseId');

  // protocol tree
  rel.setRelationshipDefinition('protocolForHorse', 'protocols', 'horses', 'horseId');
  rel.setRelationshipDefinition('phasesOfProtocol', 'protocolPhases', 'protocols', 'protocolId');
  rel.setRelationshipDefinition('itemsOfPhase', 'protocolPhaseItems', 'protocolPhases', 'phaseId');
  rel.setRelationshipDefinition('tasksOfProtocol', 'protocolTasks', 'protocols', 'protocolId');
  rel.setRelationshipDefinition('tasksOfPhase', 'protocolTasks', 'protocolPhases', 'phaseId');
  rel.setRelationshipDefinition('analysisOfProtocol', 'protocolAnalyses', 'protocols', 'protocolId');
  rel.setRelationshipDefinition('adviceOfAnalysis', 'protocolAdvice', 'protocolAnalyses', 'analysisId');
  rel.setRelationshipDefinition('completionsOfTask', 'protocolTaskCompletions', 'protocolTasks', 'taskId');

  // scanner
  rel.setRelationshipDefinition('ingredientsOfScan', 'scanIngredients', 'scanResults', 'scanId');

  // library
  rel.setRelationshipDefinition('chaptersOfItem', 'libraryChapters', 'libraryItems', 'itemId');
  rel.setRelationshipDefinition('sectionsOfItem', 'libraryArticleSections', 'libraryItems', 'itemId');
  rel.setRelationshipDefinition('bookmarksForItem', 'libraryBookmarks', 'libraryItems', 'itemId');
  rel.setRelationshipDefinition('progressForItem', 'libraryProgress', 'libraryItems', 'itemId');

  // community
  rel.setRelationshipDefinition('repliesOfPost', 'communityReplies', 'communityPosts', 'postId');
  rel.setRelationshipDefinition('tagsOfPost', 'communityPostTags', 'communityPosts', 'postId');
  rel.setRelationshipDefinition('postCategory', 'communityPosts', 'communityCategories', 'categoryId');

  // subscription
  rel.setRelationshipDefinition('subscriptionPlan', 'subscriptions', 'plans', 'planId');
  rel.setRelationshipDefinition('benefitsOfPlan', 'planBenefits', 'plans', 'planId');
  rel.setRelationshipDefinition('paymentsOfSub', 'payments', 'subscriptions', 'subscriptionId');

  // chat
  rel.setRelationshipDefinition('messagesOfSession', 'chatMessages', 'chatSessions', 'sessionId');
}
