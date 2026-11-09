<?php

/**
 * @file
 * One-time local builder for the talk companion pages feature (Nov 2026).
 *
 * Creates the config that config/sync then carries to live:
 *   - field_event on Meeting (session), with the sfdug_meetings view
 *     filtered to SFDUG meetings only;
 *   - core comment module, a "Discussion" comment type, and a threaded,
 *     moderated field_discussion field on Meeting;
 *   - anonymous/authenticated may read and post comments, nobody skips
 *     approval; Honeypot protects the comment form.
 *
 * Run once locally:  ddev drush php:script scripts/build_talk_pages.php
 * Then export, review, and copy only this feature's files into config/sync.
 * Live gets the result through `drush cim`, never by running this script.
 * Idempotent: re-running changes nothing that already exists.
 */

use Drupal\comment\CommentInterface;
use Drupal\comment\CommentManagerInterface;
use Drupal\comment\Entity\CommentType;
use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\views\Entity\View;

$display_repo = \Drupal::service('entity_display.repository');
$out = fn(string $m) => print($m . "\n");

// --- 1. field_event ------------------------------------------------------
if (!FieldStorageConfig::loadByName('node', 'field_event')) {
  FieldStorageConfig::create([
    'field_name' => 'field_event',
    'entity_type' => 'node',
    'type' => 'list_string',
    'cardinality' => 1,
    'translatable' => TRUE,
    'settings' => [
      // Runtime format (value => label); the options module writes the
      // value/label list that config/sync shows.
      'allowed_values' => [
        'sfdug' => 'SFDUG meeting',
        'badcamp' => 'BADCamp',
        'drupalcon' => 'DrupalCon',
        'other' => 'Other',
      ],
      'allowed_values_function' => '',
    ],
  ])->save();
  $out('created field.storage.node.field_event');
}
if (!FieldConfig::loadByName('node', 'session', 'field_event')) {
  FieldConfig::create([
    'field_name' => 'field_event',
    'entity_type' => 'node',
    'bundle' => 'session',
    'label' => 'Event',
    'description' => 'Which event this talk belongs to. Only SFDUG meetings appear in the meeting listings and the home page hero; everything appears in the video archive.',
    'required' => TRUE,
    'translatable' => TRUE,
    'default_value' => [['value' => 'sfdug']],
  ])->save();
  $out('created field.field.node.session.field_event');
}

// --- 3a. comment module, type, body --------------------------------------
if (!\Drupal::moduleHandler()->moduleExists('comment')) {
  \Drupal::service('module_installer')->install(['comment']);
  $out('enabled comment');
}
if (!CommentType::load('meeting_comment')) {
  CommentType::create([
    'id' => 'meeting_comment',
    'label' => 'Discussion',
    'target_entity_type_id' => 'node',
    'description' => 'Moderated discussion attached to a Meeting page.',
  ])->save();
  \Drupal::service('comment.manager')->addBodyField('meeting_comment');
  $out('created comment type meeting_comment with comment_body');
}

// --- 3b. field_discussion on Meeting -------------------------------------
if (!FieldStorageConfig::loadByName('node', 'field_discussion')) {
  FieldStorageConfig::create([
    'field_name' => 'field_discussion',
    'entity_type' => 'node',
    'type' => 'comment',
    'translatable' => TRUE,
    'settings' => ['comment_type' => 'meeting_comment'],
  ])->save();
  $out('created field.storage.node.field_discussion');
}
if (!FieldConfig::loadByName('node', 'session', 'field_discussion')) {
  FieldConfig::create([
    'field_name' => 'field_discussion',
    'entity_type' => 'node',
    'bundle' => 'session',
    'label' => 'Discussion',
    'description' => 'Open on talk companion pages; hidden on ordinary meetings. Every comment waits for approval.',
    'required' => FALSE,
    'translatable' => TRUE,
    'default_value' => [[
      'status' => CommentItemInterface::OPEN,
      'cid' => 0,
      'last_comment_timestamp' => 0,
      'last_comment_name' => NULL,
      'last_comment_uid' => 0,
      'comment_count' => 0,
    ]],
    'settings' => [
      'default_mode' => CommentManagerInterface::COMMENT_MODE_THREADED,
      'per_page' => 50,
      'anonymous' => CommentInterface::ANONYMOUS_MAY_CONTACT,
      'form_location' => CommentItemInterface::FORM_BELOW,
      'preview' => DRUPAL_DISABLED,
    ],
  ])->save();
  $out('created field.field.node.session.field_discussion');
}

// --- form display: field_event after the date, comment settings last -----
$form = $display_repo->getFormDisplay('node', 'session', 'default');
$order = [
  'field_session_date' => 1, 'field_event' => 2, 'field_event_mode' => 3,
  'field_location' => 4, 'field_session_video' => 5, 'body' => 6,
  'field_short_description' => 7, 'field_person_speakers' => 8,
  'field_rsvp_url' => 9, 'field_slides' => 10, 'field_social_media_card' => 11,
  'field_discussion' => 12,
];
foreach ($order as $name => $weight) {
  $component = $form->getComponent($name) ?? [];
  if ($name === 'field_event') {
    $component += ['type' => 'options_select', 'settings' => [], 'third_party_settings' => []];
  }
  if ($name === 'field_discussion') {
    $component += ['type' => 'comment_default', 'settings' => [], 'third_party_settings' => []];
  }
  $component['weight'] = $weight;
  $component['region'] = 'content';
  $form->setComponent($name, $component);
}
$form->save();
$out('updated form display node.session.default');

// --- view displays: comments on full only; field_event nowhere -----------
foreach (['default', 'full', 'card', 'card_archive', 'featured', 'infobar', 'teaser'] as $mode) {
  $view = $display_repo->getViewDisplay('node', 'session', $mode);
  $view->removeComponent('field_event');
  if ($mode === 'full') {
    $view->setComponent('field_discussion', [
      'type' => 'comment_default',
      'label' => 'hidden',
      'weight' => 3,
      'region' => 'content',
      'settings' => ['view_mode' => 'default', 'pager_id' => 0],
      'third_party_settings' => [],
    ]);
  }
  else {
    $view->removeComponent('field_discussion');
  }
  $view->save();
}
$out('updated view displays node.session.*');

// --- 3c. permissions ------------------------------------------------------
foreach (['anonymous', 'authenticated'] as $rid) {
  user_role_grant_permissions($rid, ['access comments', 'post comments']);
}
foreach (\Drupal\user\Entity\Role::loadMultiple() as $role) {
  if (!$role->isAdmin() && $role->hasPermission('skip comment approval')) {
    user_role_revoke_permissions($role->id(), ['skip comment approval']);
    $out('revoked skip comment approval from ' . $role->id());
  }
}
$out('granted access/post comments to anonymous and authenticated');

// --- 3d. honeypot on the Discussion comment form --------------------------
\Drupal::configFactory()->getEditable('honeypot.settings')
  ->set('form_settings.comment_meeting_comment_form', TRUE)
  ->save();
$out('honeypot protects comment_meeting_comment_form');

// --- 2. sfdug_meetings: every display shows SFDUG meetings only -----------
$view = View::load('sfdug_meetings');
$filter = [
  'id' => 'field_event_value',
  'table' => 'node__field_event',
  'field' => 'field_event_value',
  'plugin_id' => 'list_field',
  'operator' => 'or',
  'value' => ['sfdug' => 'sfdug'],
  'group' => 1,
];
$displays = $view->get('display');
foreach ($displays as $id => $display) {
  if (isset($display['display_options']['filters']) && !isset($display['display_options']['filters']['field_event_value'])) {
    $displays[$id]['display_options']['filters']['field_event_value'] = $filter;
  }
}
$view->set('display', $displays);
$view->save();
$out('filtered every sfdug_meetings display to field_event = sfdug');

drupal_flush_all_caches();
$out('done');
