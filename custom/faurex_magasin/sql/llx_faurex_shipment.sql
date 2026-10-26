CREATE TABLE IF NOT EXISTS llx_faurex_shipment (
  rowid          INT AUTO_INCREMENT PRIMARY KEY,
  ref            VARCHAR(64) NOT NULL,
  fk_facture     INT NOT NULL,
  fk_soc         INT NOT NULL,
  date_facture   DATE,
  date_planned   DATE,
  status         TINYINT NOT NULL DEFAULT 0,
  date_creation  DATETIME,
  fk_user_creat  INT,
  note           TEXT,
  UNIQUE KEY uk_faurex_shipment_ref (ref),
  KEY idx_faurex_shipment_facture (fk_facture)
) ENGINE=InnoDB;
