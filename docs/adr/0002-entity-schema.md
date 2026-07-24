# ADR 0002: Entity and persistence schema

- Status: Accepted
- Date: 2026-07-22
- Scope: Full schema contract; tables are introduced incrementally by milestone

## Context

`local_outcomemap` is the system of record. The schema must represent stable academic identities, effective-dated governance, version-specific mappings, deterministic evidence and results, immutable snapshots, and append-only audit history on MariaDB/MySQL and PostgreSQL through Moodle XMLDB/DML.

## Conventions

- Primary keys are XMLDB `INT(10)`, sequence, named `id`.
- Stable interchange IDs are lowercase RFC 4122 UUID strings in `CHAR(36)` with unique indexes.
- Core Moodle references are `INT(10)` and indexed but have no database foreign key. Services validate them and cleanup/reconciliation tasks detect missing references.
- Foreign keys between plugin tables are declared in XMLDB. Governed and historical rows are restricted from physical deletion; UI deletion means retirement.
- Timestamps are Unix seconds in `INT(10)`. Nullable `effectiveto` means open-ended.
- Authoritative decimal fields are XMLDB `NUMBER(20,10)`. DML values are handled as canonical decimal strings.
- States are `draft`, `needs_review`, `approved`, or `retired`, validated in services. Result-specific states are listed below.
- `createdby`, `modifiedby`, and `approvedby` are nullable core user IDs with indexes and no core foreign keys.
- JSON payloads are canonical UTF-8 JSON in `TEXT`; payload hashes use lowercase SHA-256 in `CHAR(64)`.
- All mutations use delegated transactions and append an audit row in the same transaction.

## Tables

### `local_outcomemap_program`

Fields: `id`, `uuid CHAR(36)`, `code CHAR(100)`, `name CHAR(255)`, `description TEXT nullable`, `externalid CHAR(255) nullable`, `programtype CHAR(20) default graduate`, `credential CHAR(20) default degree`, `status CHAR(20)`, `createdby INT nullable`, `modifiedby INT nullable`, `timecreated INT`, `timemodified INT`.

Constraints/indexes: primary `id`; unique `uuid`; unique `code`; nonunique `externalid`, `programtype`, `status`, `createdby`, `modifiedby`.

Validation: `programtype` is `graduate`, `undergraduate`, or `specialization`; `credential` is `degree` or `certificate`. Defaults preserve existing records and older service callers, while new UI and CSV inputs make both choices explicit.

Deletion: restrict while referenced; retire instead.

### `local_outcomemap_course`

Fields: `id`, `uuid CHAR(36)`, `code CHAR(100)`, `name CHAR(255)`, `description TEXT nullable`, `siskey CHAR(255) nullable`, `status CHAR(20)`, `createdby INT nullable`, `modifiedby INT nullable`, `timecreated INT`, `timemodified INT`.

Constraints/indexes: primary `id`; unique `uuid`; unique `code`; nonunique `siskey`, `status`, `createdby`, `modifiedby`.

Deletion: restrict while referenced; retire instead.

### `local_outcomemap_cinst`

Fields: `id`, `uuid CHAR(36)`, `courseid INT`, `moodlecourseid INT`, `periodcode CHAR(100)`, `externalid CHAR(255) nullable`, `status CHAR(20)`, `confirmed INT(1) default 0`, `confirmedby INT nullable`, `confirmedat INT nullable`, `createdby INT nullable`, `modifiedby INT nullable`, `timecreated INT`, `timemodified INT`.

Constraints/indexes: primary `id`; foreign `courseid -> local_outcomemap_course.id`; unique `uuid`; unique `(moodlecourseid, periodcode)`; nonunique `courseid`, `periodcode`, `externalid`, `status`, `confirmedby`.

Context: `moodlecourseid` resolves to course context. Restore inserts `draft`, `confirmed=0`.

Deletion: core course deletion retires/unconfirms the association; historical evidence remains resolvable.

### `local_outcomemap_progcourse`

Fields: `id`, `uuid CHAR(36)`, `programid INT`, `courseid INT`, `status CHAR(20)`, `effectivefrom INT`, `effectiveto INT nullable`, `createdby INT nullable`, `approvedby INT nullable`, `timecreated INT`, `timemodified INT`, `approvedat INT nullable`.

Constraints/indexes: primary `id`; foreign keys to program and course; unique `uuid`; unique `(programid, courseid, effectivefrom)`; nonunique `(programid,status)`, `(courseid,status)`, `effectivefrom`, `effectiveto`.

Validation: approved ranges for the same pair may not overlap.

Deletion: restrict; retire/end-date.

### `local_outcomemap_fw`

Fields: `id`, `uuid CHAR(36)`, `code CHAR(100)`, `name CHAR(255)`, `description TEXT nullable`, `ownertype CHAR(20)`, `ownerid INT nullable`, `status CHAR(20)`, `createdby INT nullable`, `modifiedby INT nullable`, `timecreated INT`, `timemodified INT`.

Constraints/indexes: primary `id`; unique `uuid`; unique `(ownertype, ownerid, code)`; nonunique `(ownertype,ownerid)`, `status`.

Validation: owner type is `institution`, `program`, or `catalog_course`; institution has null owner, others resolve to the corresponding plugin table.

Deletion: restrict while outcomes exist; retire instead.

### `local_outcomemap_item`

Fields: `id`, `uuid CHAR(36)`, `frameworkid INT`, `code CHAR(100)`, `status CHAR(20)`, `createdby INT nullable`, `timecreated INT`, `timemodified INT`.

Constraints/indexes: primary `id`; foreign `frameworkid -> fw.id`; unique `uuid`; unique `(frameworkid,code)`; nonunique `(frameworkid,status)`.

Deletion: never physically delete after approval or reference; retire.

### `local_outcomemap_itemver`

Fields: `id`, `uuid CHAR(36)`, `itemid INT`, `version INT`, `statement TEXT`, `shortstatement CHAR(255) nullable`, `bloomlevel CHAR(50) nullable`, `status CHAR(20)`, `effectivefrom INT`, `effectiveto INT nullable`, `changereason TEXT nullable`, `createdby INT nullable`, `approvedby INT nullable`, `timecreated INT`, `timemodified INT`, `approvedat INT nullable`.

Constraints/indexes: primary `id`; foreign `itemid -> item.id`; unique `uuid`; unique `(itemid,version)`; nonunique `(itemid,status)`, `effectivefrom`, `effectiveto`, `approvedby`.

Validation: version is positive and monotonic; approved effective ranges for one item cannot overlap; an approved row is immutable and is replaced by a new version.

Deletion: draft-only physical deletion when unreferenced; otherwise restrict.

### `local_outcomemap_rel`

Fields: `id`, `relationuuid CHAR(36)`, `version INT`, `sourceitemid INT`, `targetitemid INT`, `type CHAR(30)`, `weight NUMBER(20,10) nullable`, `status CHAR(20)`, `effectivefrom INT`, `effectiveto INT nullable`, `notes TEXT nullable`, `createdby INT nullable`, `approvedby INT nullable`, `timecreated INT`, `timemodified INT`, `approvedat INT nullable`.

Constraints/indexes: primary `id`; foreign source/target to item; unique `(relationuuid,version)`; nonunique `(sourceitemid,type,status)`, `(targetitemid,type,status)`, `effectivefrom`, `effectiveto`.

Validation: source differs from target; type is `is_child_of`, `aligns_to`, `contributes_to`, `replaced_by`, or `related_to`; only `contributes_to` may have positive weight; no approved effective-range overlap per relation UUID; approval runs cycle detection for `is_child_of` and `contributes_to` over records effective in the same interval.

Deletion: approved rows are immutable; create a later retired/end-dated version.

### `local_outcomemap_cmmap`

Fields: `id`, `mappinguuid CHAR(36)`, `version INT`, `cinstid INT`, `cmid INT`, `itemverid INT`, `role CHAR(20)`, `weight NUMBER(20,10) nullable`, `priority INT default 0`, `notes TEXT nullable`, `status CHAR(20)`, `effectivefrom INT`, `effectiveto INT nullable`, `createdby INT nullable`, `approvedby INT nullable`, `timecreated INT`, `timemodified INT`, `approvedat INT nullable`.

Constraints/indexes: primary; foreign cinst/itemver; unique `(mappinguuid,version)`; nonunique `(cinstid,cmid,status)`, `(itemverid,role,status)`, `effectivefrom`, `effectiveto`.

Validation: `cmid` belongs to the associated Moodle course; internal target is resolved at display time.

Deletion: draft-only when unreferenced; otherwise version/retire.

### `local_outcomemap_secmap`

Fields: `id`, `mappinguuid CHAR(36)`, `version INT`, `cinstid INT`, `sectionid INT`, `itemverid INT`, `role CHAR(20)`, `weight NUMBER(20,10) nullable`, `priority INT default 0`, `notes TEXT nullable`, `status CHAR(20)`, `effectivefrom INT`, `effectiveto INT nullable`, `createdby INT nullable`, `approvedby INT nullable`, `timecreated INT`, `timemodified INT`, `approvedat INT nullable`.

Constraints/indexes: primary; foreign cinst/itemver; unique `(mappinguuid,version)`; nonunique `(cinstid,sectionid,status)`, `(itemverid,role,status)`, effective dates.

Validation/deletion: same mapping rules as cmmap; section must belong to the associated course.

### `local_outcomemap_qmap`

Fields: `id`, `mappinguuid CHAR(36)`, `version INT`, `questionversionid INT`, `questionid INT`, `itemverid INT`, `role CHAR(20)`, `weight NUMBER(20,10) nullable`, `notes TEXT nullable`, `status CHAR(20)`, `effectivefrom INT`, `effectiveto INT nullable`, `createdby INT nullable`, `approvedby INT nullable`, `timecreated INT`, `timemodified INT`, `approvedat INT nullable`.

Constraints/indexes: primary; foreign `itemverid`; unique `(mappinguuid,version)`; nonunique `(questionversionid,status)`, `(questionid,status)`, `(itemverid,role,status)`, effective dates.

Validation: question/version pair must match core; approved assessed weights for a question version are positive and total exactly `1.0000000000`; copied mappings receive new mapping UUIDs, version 1, and draft status.

Deletion: approved mappings are immutable and remain for historical attempts.

### `local_outcomemap_policy`

Fields: `id`, `policyuuid CHAR(36)`, `version INT`, `policytype CHAR(50)`, `scopetype CHAR(30)`, `scopeid INT nullable`, `name CHAR(255)`, `configjson TEXT`, `confighash CHAR(64)`, `status CHAR(20)`, `effectivefrom INT`, `effectiveto INT nullable`, `createdby INT nullable`, `approvedby INT nullable`, `timecreated INT`, `timemodified INT`, `approvedat INT nullable`.

Constraints/indexes: primary; unique `(policyuuid,version)`; unique `confighash` is not required because identical policies may have different scope; nonunique `(policytype,scopetype,scopeid,status)`, effective dates.

Validation: known versioned JSON schema per policy type; approved ranges at the same scope/type do not overlap. Scope precedence is assessment, course instance, catalog course, institution.

Deletion: approved policy immutable; version/retire.

### `local_outcomemap_band`

Fields: `id`, `policyid INT`, `code CHAR(50)`, `name CHAR(255)`, `description TEXT nullable`, `minpercent NUMBER(20,10) nullable`, `mininclusive INT(1) default 1`, `maxpercent NUMBER(20,10) nullable`, `maxinclusive INT(1) default 0`, `sortorder INT`.

Constraints/indexes: primary; foreign `policyid -> policy.id`; unique `(policyid,code)`; unique `(policyid,sortorder)`.

Validation: ranges do not overlap and use policy precision; no seeded pass threshold.

Deletion: cascades only when deleting an unapproved, unreferenced draft policy; otherwise restrict.

### `local_outcomemap_evidence`

Fields: `id`, `uuid CHAR(36)`, `lineageuuid CHAR(36)`, `dedupekey CHAR(64)`, `sourceevidenceid INT nullable`, `relationpathjson TEXT nullable`, `cinstid INT`, `userid INT`, `assessmentcmid INT`, `quizattemptid INT`, `questionusageid INT`, `slot INT`, `questionattemptid INT`, `questionversionid INT`, `questionid INT`, `itemverid INT`, `mappingid INT`, `policyid INT`, `evidencetype CHAR(20)`, `rawfraction NUMBER(20,10) nullable`, `rawmark NUMBER(20,10) nullable`, `maxmark NUMBER(20,10)`, `mappingweight NUMBER(20,10)`, `relationweight NUMBER(20,10) default 1`, `weightedearned NUMBER(20,10)`, `weightedpossible NUMBER(20,10)`, `gradingstate CHAR(30)`, `attempttime INT`, `gradingtime INT nullable`, `supersededby INT nullable`, `timecreated INT`, `timemodified INT`.

Constraints/indexes: primary; foreign cinst/itemver/qmap mapping/policy plus self `sourceevidenceid` and `supersededby`; unique `uuid`; unique `dedupekey`; nonunique `lineageuuid`, `sourceevidenceid`, `(userid,cinstid,itemverid)`, `quizattemptid`, `questionattemptid`, `questionversionid`, `mappingid`, `policyid`, `gradingstate`.

Validation: direct evidence starts a lineage; inherited evidence shares that lineage, references its direct source, and stores the exact ordered relation IDs in canonical `relationpathjson`; one active dedupe key exists per source/mapping/policy/target. Regrade inserts a replacement, marks prior authoritative evidence superseded, and records an audit event.

Deletion: personal-data deletion rules apply only when not frozen/referenced; otherwise anonymise under approved retention policy.

### `local_outcomemap_result`

Fields: `id`, `uuid CHAR(36)`, `resultkey CHAR(64)`, `version INT`, `cinstid INT nullable`, `userid INT nullable`, `scopetype CHAR(30)`, `scopeid INT nullable`, `periodcode CHAR(100) nullable`, `itemverid INT`, `policyid INT`, `numerator NUMBER(20,10)`, `denominator NUMBER(20,10)`, `percentage NUMBER(20,10) nullable`, `distinctitems INT default 0`, `bandid INT nullable`, `state CHAR(30)`, `stale INT(1) default 0`, `algoversion CHAR(50)`, `inputhash CHAR(64)`, `lineagejson TEXT`, `lineagehash CHAR(64)`, `supersededby INT nullable`, `timecalculated INT`, `timecreated INT`, `timemodified INT`.

Constraints/indexes: primary; foreign cinst/itemver/policy/band and self supersededby; unique `uuid`; unique `(resultkey,version)`; nonunique `(userid,cinstid,itemverid,state)`, `(scopetype,scopeid,periodcode)`, `inputhash`, `lineagehash`, `stale`.

Validation: states are `not_assessed`, `insufficient_evidence`, `calculation_pending`, `calculated`, `superseded`, `frozen`; `lineagejson` is the canonical sorted list of exact evidence UUIDs, values, and selected relation paths and must hash to `lineagehash`; same canonical inputs and algorithm produce the same values and input hash. Current nonfrozen result is superseded by a new version rather than silently rewriting reported history.

Deletion: restrict if snapshotted; otherwise privacy policy applies.

### `local_outcomemap_remed`

Fields: `id`, `mappinguuid CHAR(36)`, `version INT`, `cinstid INT`, `itemverid INT`, `bandid INT nullable`, `targettype CHAR(20)`, `targetid INT nullable`, `externalurl TEXT nullable`, `title CHAR(255)`, `explanation TEXT nullable`, `priority INT default 0`, `required INT(1) default 0`, `minpercent NUMBER(20,10) nullable`, `maxpercent NUMBER(20,10) nullable`, `status CHAR(20)`, `effectivefrom INT`, `effectiveto INT nullable`, `createdby INT nullable`, `approvedby INT nullable`, `timecreated INT`, `timemodified INT`, `approvedat INT nullable`.

Constraints/indexes: primary; foreign cinst/itemver/band; unique `(mappinguuid,version)`; nonunique `(cinstid,itemverid,status)`, `(bandid,status)`, `(targettype,targetid)`, `(priority,status)`.

Validation: target type is `course_module`, `course_section`, `external_url`, or future approved type; exactly one of internal target ID/external URL is populated; internal targets belong to the course. URL is cleaned and allowlisted by policy.

Deletion: approved rows version/retire.

### `local_outcomemap_snapshot`

Fields: `id`, `snapshotuuid CHAR(36)`, `version INT`, `periodcode CHAR(100)`, `name CHAR(255)`, `status CHAR(20)`, `scopejson TEXT`, `scopehash CHAR(64)`, `algoversion CHAR(50)`, `pluginversion INT`, `notes TEXT nullable`, `correctionreason TEXT nullable`, `createdby INT nullable`, `approvedby INT nullable`, `timecreated INT`, `approvedat INT nullable`.

Constraints/indexes: primary; unique `(snapshotuuid,version)`; unique `scopehash` not required; nonunique `(periodcode,status)`, `createdby`, `approvedby`.

Validation: status progresses draft to approved/frozen; frozen rows are immutable. Corrections create a new version with reason.

Deletion: frozen snapshots cannot be deleted through normal plugin APIs.

### `local_outcomemap_snapitem`

Fields: `id`, `snapshotid INT`, `itemtype CHAR(30)`, `sourceid INT nullable`, `sourceuuid CHAR(36) nullable`, `sortorder INT`, `payloadjson TEXT`, `payloadhash CHAR(64)`.

Constraints/indexes: primary; foreign `snapshotid -> snapshot.id`; unique `(snapshotid,sortorder)`; nonunique `(snapshotid,itemtype)`, `(snapshotid,itemtype,sourceid)`, `sourceuuid`, `payloadhash`. The sort order is assigned canonically, avoiding cross-database differences in unique constraints containing nullable `sourceid`.

Purpose: immutable canonical rows for included courses, users/subject references, assessments, evidence, results, definition/relation/mapping/policy versions, and calculated values.

Deletion: only cascade with deletion of an unfrozen draft snapshot; otherwise restrict.

### `local_outcomemap_audit`

Fields: `id`, `eventuuid CHAR(36)`, `actorid INT nullable`, `contextid INT`, `action CHAR(50)`, `objecttype CHAR(50)`, `objectid INT nullable`, `objectuuid CHAR(36) nullable`, `beforejson TEXT nullable`, `afterjson TEXT nullable`, `reason TEXT nullable`, `correlationid CHAR(36)`, `iphash CHAR(64) nullable`, `timecreated INT`.

Constraints/indexes: primary; unique `eventuuid`; nonunique `(objecttype,objectid)`, `objectuuid`, `actorid`, `contextid`, `action`, `correlationid`, `timecreated`.

Rules: insert only; no update API; payloads are canonical and privacy-minimised. Institutional audit events may be retained with actor anonymisation according to policy.

## Cross-table invariants

1. Approved effective ranges do not overlap for the same stable identity and scope.
2. Historical approved rows are never updated except for an explicitly modelled retirement transition that itself emits audit history; preferred behavior is a new version.
3. Every approved mutation records approver and approval time.
4. Graph cycle checks lock or transactionally recheck affected approved edges before commit.
5. Mapping services verify exact item versions and core target ownership.
6. Evidence/result/snapshot lineage stores exact row IDs and canonical hashes; UUIDs support interchange, not database joins.
7. No database cascade can erase approved definitions, mappings, evidence, results, audit, or frozen snapshots.
8. XMLDB names and indexes must be revalidated with Moodle's XMLDB editor when each milestone introduces its subset.

## Milestone rollout

- Milestone 1: program, course, cinst, progcourse, fw, item, itemver, rel, audit.
- Milestone 2: cmmap, secmap, remed plus backup/restore structures.
- Milestone 3: qmap.
- Milestone 4: policy, band, evidence, result.
- Milestone 6: snapshot, snapitem.

Later additions are additive. Once a milestone is complete, existing fields and public semantics are not repurposed.
