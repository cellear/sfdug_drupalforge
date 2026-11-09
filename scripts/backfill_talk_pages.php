<?php

/**
 * @file
 * Backfill for the talk companion pages feature. Safe to run repeatedly.
 *
 * Config defaults apply to new nodes only, so after `drush cim` every
 * existing Meeting has an empty field_event and an empty field_discussion.
 * This sets field_event = sfdug and comments = hidden on exactly those
 * nodes, and leaves anything that already has a value alone. Run it right
 * after the config import and before creating the BADCamp Meetings.
 *
 *   ddev drush php:script scripts/backfill_talk_pages.php          (local)
 *   /usr/local/bin/php83 vendor/bin/drush php:script scripts/backfill_talk_pages.php  (pair)
 */

use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\node\Entity\Node;

$nids = \Drupal::entityQuery('node')
  ->accessCheck(FALSE)
  ->condition('type', 'session')
  ->execute();

$event = $comments = $skipped = 0;
foreach (Node::loadMultiple($nids) as $node) {
  $changed = FALSE;
  if ($node->hasField('field_event') && $node->get('field_event')->isEmpty()) {
    $node->set('field_event', 'sfdug');
    $event++;
    $changed = TRUE;
  }
  if ($node->hasField('field_discussion') && $node->get('field_discussion')->isEmpty()) {
    $node->set('field_discussion', ['status' => CommentItemInterface::HIDDEN]);
    $comments++;
    $changed = TRUE;
  }
  if ($changed) {
    // No new revision, and no bump to the changed timestamp: this is
    // housekeeping, not an edit.
    $node->setNewRevision(FALSE);
    $node->setSyncing(TRUE);
    $node->save();
  }
  else {
    $skipped++;
  }
}

printf("Meetings: %d total. field_event set on %d, comments hidden on %d, untouched %d.\n",
  count($nids), $event, $comments, $skipped);
