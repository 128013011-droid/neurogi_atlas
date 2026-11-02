-- Run once in phpMyAdmin (SQL tab) on the neurogi_atlas database.
-- Each row says: when a search contains this keyword, also show images from this dataset study_id.
CREATE TABLE IF NOT EXISTS atlas_dataset_link (
  id INT AUTO_INCREMENT PRIMARY KEY,
  keyword VARCHAR(100) NOT NULL,
  study_id VARCHAR(100) NOT NULL,
  note VARCHAR(255) DEFAULT NULL
) CHARACTER SET utf8mb4;

-- Your decision: this shows HyperKvasir images when someone searches IBS.
-- HyperKvasir is a general GI endoscopy dataset, not an IBS cohort, so the page labels it as reference imagery.
INSERT INTO atlas_dataset_link (keyword, study_id, note)
VALUES ('IBS', 'HyperKvasir', 'Reference GI endoscopy images from a public dataset, not IBS patients');
