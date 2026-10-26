# Déploiement Faurex Magasin sur cPanel

## 1. Fichiers à transférer

Le module = **un seul dossier** : `faurex_magasin/`.

À uploader **tel quel** dans le dossier `custom/` de votre installation Dolibarr distante :

```
public_html/                          (ou htdocs/ selon hébergeur)
└── dolibarr/                         (racine de votre Dolibarr)
    ├── conf/
    ├── core/
    ├── ...
    └── custom/                       ← cible du transfert
        └── faurex_magasin/           ← TOUT le dossier local
            ├── class/
            │   ├── faurexshipment.class.php
            │   └── faurexreception.class.php
            ├── core/
            │   ├── modules/
            │   │   └── modFaurexMagasin.class.php
            │   └── triggers/
            │       └── interface_99_modFaurexMagasin_FaurexMagasinTriggers.class.php
            ├── langs/fr_FR/faurex_magasin.lang
            ├── lib/
            ├── sql/                  ← reste sur le serveur, ne PAS importer manuellement
            │   ├── llx_faurex_shipment.sql
            │   ├── llx_faurex_shipment_line.sql
            │   ├── llx_faurex_reception.sql
            │   └── llx_faurex_reception_line.sql
            ├── shipment_card.php
            ├── shipment_list.php
            ├── reception_card.php
            └── reception_list.php
```

### Méthodes de transfert

- **Méthode rapide** : compresser le dossier `faurex_magasin/` en ZIP en local, l'uploader via le **Gestionnaire de fichiers** cPanel dans `public_html/dolibarr/custom/`, puis **Extraire** sur place.
- **FTP** : utiliser FileZilla et copier le dossier complet vers `/public_html/dolibarr/custom/faurex_magasin/`.

---

## 2. Le dossier SQL — comment le gérer

**Important** : le dossier `sql/` doit rester **dans le module**, sur le serveur — il sert à Dolibarr lui-même.

Vous avez **deux options** :

### Option A (recommandée) : import automatique par Dolibarr
- Laisser le dossier `sql/` à sa place dans `custom/faurex_magasin/sql/`.
- Activer le module depuis l'interface Dolibarr (étape 4 ci-dessous).
- Au moment de l'activation, Dolibarr lit le contenu de `sql/` et **crée toutes les tables automatiquement** (méthode `_load_tables()` du descripteur).
- ➜ **Aucun import manuel via phpMyAdmin n'est nécessaire.**

### Option B : import manuel via phpMyAdmin
À utiliser uniquement si l'activation automatique échoue (ex: hébergement avec restrictions).
1. Ouvrir phpMyAdmin (cPanel → bases de données → phpMyAdmin).
2. Sélectionner la base Dolibarr.
3. Onglet **Importer**.
4. Importer **dans l'ordre** :
   1. `llx_faurex_shipment.sql`
   2. `llx_faurex_shipment_line.sql`
   3. `llx_faurex_reception.sql`
   4. `llx_faurex_reception_line.sql`
5. Si le préfixe de tables n'est pas `llx_`, **éditer chaque fichier SQL** et remplacer `llx_` par votre préfixe avant import.

---

## 3. Vérifier la configuration `conf.php`

Le fichier `conf/conf.php` de Dolibarr **doit contenir** ces 2 lignes (sinon Dolibarr ne reconnaît pas le dossier `custom/`) :

```php
$dolibarr_main_url_root_alt = '/custom';
$dolibarr_main_document_root_alt = '/home/USER/public_html/dolibarr/custom';
```

Adapter le chemin absolu à votre hébergement (cPanel l'affiche dans le Gestionnaire de fichiers).

---

## 4. Activer le module dans Dolibarr

1. Se connecter à Dolibarr en **administrateur**.
2. Aller dans **Configuration → Modules/Applications** (URL : `/admin/modules.php`).
3. Onglet **Produits** ou champ de recherche → taper "Faurex".
4. Trouver **Faurex Magasin** → cliquer le bouton **ON/OFF** pour activer.
5. Dolibarr crée alors les 4 tables et enregistre les menus.

### Permissions
6. **Utilisateurs et groupes → [utilisateur] → Permissions** → cocher :
   - Lire les expéditions
   - Créer/modifier les expéditions
   - Lire les réceptions
   - Créer/modifier les réceptions

---

## 5. Vérifications après installation

| Vérification | Comment |
|---|---|
| Tables créées | phpMyAdmin → vérifier la présence de `llx_faurex_shipment`, `llx_faurex_shipment_line`, `llx_faurex_reception`, `llx_faurex_reception_line` |
| Menus visibles | Aller dans **Produits & Services** → menus de gauche : **Expédition** et **Réception** doivent apparaître |
| Liste vide ou peuplée | Cliquer **Expédition → Liste** : Dolibarr crée automatiquement les expéditions pour toutes les factures clients déjà validées (1 seule requête `INSERT...SELECT`) |
| Trigger actif | Valider une nouvelle facture client → l'expédition correspondante doit apparaître dans **Expédition → Liste** |
| Stock | Ajouter une ligne avec entrepôt sélectionné → vérifier dans **Produits → Stocks → Mouvements** qu'un mouvement avec libellé `Expédition EXP...` (ou `Réception REC...`) est créé |

---

## 6. Optimisations actives

Le module est déjà optimisé :

- **Listes** : sync via `INSERT INTO ... SELECT` unique (1 requête au lieu de N).
- **Fiches** : produits chargés en bloc (`WHERE rowid IN (...)`), quantités livrées agrégées en une seule requête `GROUP BY`.
- **`updateStatus`** : 1 requête `LEFT JOIN + GROUP BY` au lieu de fetch complet de la facture + N requêtes par ligne.
- **Index composites** sur `(fk_shipment, fk_facturedet)` et `(fk_reception, fk_facture_fourn_det)` pour accélérer les agrégats.
- **Trigger Dolibarr** sur `BILL_VALIDATE` / `BILL_SUPPLIER_VALIDATE` → expédition/réception créée automatiquement à la validation.
- **Mouvements de stock** automatiques (sortie sur expédition, entrée sur réception, inversion sur suppression/annulation).
- **Statuts auto-calculés** : *Validé* → *Partiel* → *Livré/Reçu* sans intervention manuelle.

---

## 7. Mise à jour ultérieure du module

Pour déployer une nouvelle version :
1. Compresser uniquement les fichiers PHP modifiés (ou tout le dossier).
2. Uploader/écraser dans `custom/faurex_magasin/` via cPanel.
3. **Si schéma SQL modifié** : exécuter manuellement les `ALTER TABLE` dans phpMyAdmin (les fichiers `sql/` ne sont relus qu'à la (ré)activation du module).
4. Pas besoin de désactiver/réactiver sauf si le descripteur `modFaurexMagasin.class.php` change (menus, permissions, dépendances).

---

## 8. Troubleshooting rapide

| Symptôme | Cause probable | Solution |
|---|---|---|
| Module non listé dans `/admin/modules.php` | `dolibarr_main_document_root_alt` mal défini dans `conf.php` | Corriger le chemin absolu |
| Tables non créées à l'activation | Permissions MySQL insuffisantes pour `CREATE TABLE` | Importer manuellement via phpMyAdmin (Option B) |
| Menus n'apparaissent pas après activation | Cache Dolibarr ou condition `enabled` cassée | Désactiver puis réactiver le module, vider le cache navigateur (Ctrl+F5) |
| Erreur `Unknown column fk_warehouse` | Module installé avant l'ajout de la colonne | `ALTER TABLE llx_faurex_shipment_line ADD COLUMN fk_warehouse INT DEFAULT NULL;` (idem pour reception_line) — fait automatiquement par le code si l'erreur survient |
| Stock non mouvementé | Entrepôt non sélectionné lors de l'ajout de ligne | Choisir un entrepôt dans la liste déroulante avant de cliquer Ajouter |
