-- View: lookup_terms

-- DROP VIEW lookup_terms;

CREATE OR REPLACE VIEW lookup_terms AS 
 SELECT tt.term_id AS id, tt.meaning_id, tt.termlist_id, t.term
   FROM termlists_terms tt
   JOIN terms t ON tt.term_id = t.id AND t.deleted = false
  WHERE tt.deleted = false;
