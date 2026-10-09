-- Game events and per-request retrieval snapshots on the event log. Safe to run more than
-- once; keeps every existing row.
--
-- kind game_event: a log-only event from event.php ("respond": false). The server stored it;
-- it never called the model, wrote history or returned actions.
-- event_type: the fixed game event type (event.php), for game_event rows and for turns that
-- an explicit dialogue event started. NULL for ordinary turns.
-- retrieval: what turn.php actually gave the model for this request, already cleaned and
-- size-capped: the search terms, the stored bio, the matched facts with id and scope, and how
-- many session history lines were sent. Never the prompt, token, keys or config. It is a copy,
-- so later fact edits and checkpoint restores do not change it.
ALTER TABLE ex_events DROP CONSTRAINT IF EXISTS ex_events_kind_check;
ALTER TABLE ex_events ADD CONSTRAINT ex_events_kind_check
    CHECK (kind IN ('turn', 'cancel', 'restore', 'game_event'));
ALTER TABLE ex_events ADD COLUMN IF NOT EXISTS event_type text
    CHECK (event_type ~ '^[a-z_]{1,32}$');
ALTER TABLE ex_events ADD COLUMN IF NOT EXISTS retrieval jsonb
    CHECK (jsonb_typeof(retrieval) = 'object' AND char_length(retrieval::text) <= 8000);
