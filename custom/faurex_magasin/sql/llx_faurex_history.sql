CREATE TABLE IF NOT EXISTS llx_faurex_history (
  rowid         INT AUTO_INCREMENT PRIMARY KEY,
  element       VARCHAR(16) NOT NULL,
  fk_element    INT NOT NULL,
  element_ref   VARCHAR(64),
  action        VARCHAR(32) NOT NULL,
  fk_product    INT DEFAULT NULL,
  fk_warehouse  INT DEFAULT NULL,
  qty_before    DOUBLE DEFAULT NULL,
  qty_after     DOUBLE DEFAULT NULL,
  motif         TEXT,
  date_action   DATETIME NOT NULL,
  fk_user       INT DEFAULT NULL,
  KEY idx_faurex_history_el (element, fk_element),
  KEY idx_faurex_history_date (date_action)
) ENGINE=InnoDB;