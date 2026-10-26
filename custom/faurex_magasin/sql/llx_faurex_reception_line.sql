CREATE TABLE IF NOT EXISTS llx_faurex_reception_line (
  rowid              INT AUTO_INCREMENT PRIMARY KEY,
  fk_reception       INT NOT NULL,
  fk_facture_fourn_det INT NOT NULL,
  fk_product         INT,
  qty_invoiced       DOUBLE NOT NULL DEFAULT 0,
  qty_received       DOUBLE NOT NULL DEFAULT 0,
  fk_warehouse       INT DEFAULT NULL,
  date_reception     DATE,
  mode_reglement     VARCHAR(64),
  account_credit     VARCHAR(64),
  date_creation      DATETIME,
  fk_user_creat      INT,
  KEY idx_faurex_reception_line_rec (fk_reception),
  KEY idx_faurex_reception_line_fdet (fk_facture_fourn_det),
  KEY idx_faurex_reception_line_rec_fdet (fk_reception, fk_facture_fourn_det)
) ENGINE=InnoDB;
