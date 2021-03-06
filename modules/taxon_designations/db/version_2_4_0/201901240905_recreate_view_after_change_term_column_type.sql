--Recreate view as it was dropped by ddmmyyyyhhmm_change_term_column_type.sql so the data type of terms.term could be changed to varchar
CREATE OR REPLACE VIEW gv_taxon_designations AS 
 SELECT td.id, td.title, t.term AS category, td.deleted
   FROM taxon_designations td
   LEFT JOIN (termlists_terms tlt
   JOIN terms t ON t.id = tlt.term_id AND t.deleted = false) ON tlt.id = td.category_id AND tlt.deleted = false
  WHERE td.deleted = false;

CREATE OR REPLACE VIEW gv_taxa_taxon_designations AS
  SELECT ttd.id, td.id AS taxon_designation_id, ttl.id AS taxa_taxon_list_id,
    tx.id as taxon_id, tx.taxon, td.title, t.term AS category, td.deleted
  FROM taxon_designations td
  JOIN taxa_taxon_designations ttd ON ttd.taxon_designation_id=td.id AND ttd.deleted=false
  JOIN taxa tx ON tx.id=ttd.taxon_id AND tx.deleted=false
  JOIN taxa_taxon_lists ttl ON ttl.taxon_id=tx.id AND ttl.deleted=false
  LEFT JOIN (
    termlists_terms tlt 
    JOIN terms t on t.id=tlt.term_id AND t.deleted=false
  ) ON tlt.id=td.category_id AND tlt.deleted=false
  WHERE td.deleted=false;

CREATE OR REPLACE VIEW detail_taxon_designations AS 
 SELECT td.id, td.title, td.code, td.abbreviation, td.description, tcat.term AS category, td.created_by_id, c.username AS created_by, td.updated_by_id, u.username AS updated_by, td.created_on, td.updated_on
   FROM taxon_designations td
   JOIN users c ON c.id = td.created_by_id
   JOIN users u ON u.id = td.updated_by_id
   JOIN termlists_terms tltcat on tltcat.id = td.category_id and tltcat.deleted=false
   JOIN terms tcat on tcat.id=tltcat.term_id and tcat.deleted=false
  WHERE td.deleted = false;

CREATE OR REPLACE VIEW detail_taxa_taxon_designations AS 
 SELECT DISTINCT ttd.id, td.title, td.code, td.abbreviation, t.taxon, tcommon.taxon as common, tpref.taxon as preferred_name, l.iso as language, tg.title as taxon_group, tcat.term AS category, ttd.created_by_id, c.username AS created_by, ttd.updated_by_id, u.username AS updated_by, ttd.created_on, ttd.updated_on
   FROM taxon_designations td
   JOIN taxa_taxon_designations ttd ON ttd.taxon_designation_id = td.id and ttd.deleted=false
   JOIN taxa t ON t.id=ttd.taxon_id AND t.deleted=false
   JOIN taxa_taxon_lists ttl ON ttl.taxon_id = ttd.taxon_id and ttl.deleted=false
   JOIN taxa_taxon_lists ttlpref ON ttlpref.taxon_meaning_id = ttl.taxon_meaning_id and ttlpref.taxon_list_id=ttl.taxon_list_id and ttlpref.preferred=true
   JOIN taxa tpref on tpref.id=ttlpref.taxon_id
   JOIN languages l on l.id=t.language_id
   JOIN taxon_groups tg on tg.id=t.taxon_group_id
   LEFT JOIN taxa tcommon on tcommon.id=ttl.common_taxon_id and tcommon.deleted=false
   JOIN termlists_terms tltcat on tltcat.id = td.category_id and tltcat.deleted=false
   JOIN terms tcat on tcat.id=tltcat.term_id and tcat.deleted=false
   JOIN users c ON c.id = ttd.created_by_id
   JOIN users u ON u.id = ttd.updated_by_id
  WHERE ttd.deleted = false;