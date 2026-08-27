# Logical Data Model

This is a logical model. Initial WordPress storage may use posts/meta/custom tables, but logical entities must remain explicit.

## KnowledgeSource

- source_id
- wp_object_id when applicable
- source_type
- canonical_url
- title
- content_hash
- visibility
- classification: static/live/mixed/ignore/review
- category
- confidence
- classifier: rule/AI
- modified_at_source
- last_scanned_at
- state: active/missing/excluded/error

## KnowledgeArtifact

- artifact_id
- source_id
- title
- stable content
- category
- visibility
- approval_state: draft/approved/needs_review/retired
- extraction_version
- content_hash
- reviewed_at
- managed_by_scan

## KnowledgeChunk

- chunk_id
- artifact_id
- ordinal
- text
- token estimate
- retrieval/index reference
- source anchor
- visibility

## Conversation / Message

Conversation records user, timestamps, status, and retention class. Messages are stored only according to the configured retention policy.

## UsageLedger

- user_id
- period
- request_count
- input/output tokens
- tool calls
- estimated cost
- blocked_count
- rate-limit events

## Escalation

- escalation_id
- conversation/user references
- category
- summary
- original question
- sources searched
- Fluent Support ticket ID
- state/timestamps

## ContactRoute

- ORAS category
- Fluent Support mailbox/tags/team/agent mapping
- enabled/fallback route

## SyncRun

- run ID/mode/times
- discovered/changed/static/live/mixed/ignored/review/error counts
- classifier/rule/model version

## AuditEvent

- actor
- action
- subject
- outcome
- timestamp
- minimal metadata
