# Plan CRUD & Métiers - Gestion des Imprévus

## 📋 Vue d'ensemble des Entités

### **Imprevus** (Imprévus Possibles - Admin)
| Champ | Type | Description |
|-------|------|-------------|
| id | int | Identifiant unique |
| titre | string(150) | Nom de l'imprévu (ex: "Panne Voiture") |
| type | string(10) | POSITIF ou NEGATIF |
| budget | float | Montant par défaut |
| messageEducatif | text | Conseil affiché |
| casRelles | OneToMany | Lié aux cas réels |

### **CasRelles** (Cas Réels - Utilisateur)
| Champ | Type | Description |
|-------|------|-------------|
| id | int | Identifiant unique |
| user | ManyToOne | Utilisateur concerné |
| imprerus | ManyToOne | Imprévu déclencheur (nullable) |
| epargne | ManyToOne | Compte épargne touché |
| titre | string(150) | Titre du cas |
| description | text | Description détaillée |
| type | string(10) | POSITIF ou NEGATIF |
| montant | float | Montant du cas |
| solution | string(30) | Solution choisie |
| dateEffet | date | Date de l'événement |
| resultat | string(20) | EN_ATTENTE, TRAITE, ANNULE |

---

## 🔧 CRUD - Imprevus (Admin Dashboard)

### Routes Admin
```
GET    /admin/imprevus              → Liste avec recherche & tri
GET    /admin/imprevus/new          → Formulaire création
POST   /admin/imprevus/new          → Traitement création
GET    /admin/imprevus/{id}         → Détail
GET    /admin/imprevus/{id}/edit    → Formulaire édition
POST   /admin/imprevus/{id}/edit    → Traitement édition
POST   /admin/imprevus/{id}/delete  → Suppression
```

### Fonctionnalités Avancées
- **Recherche**: Par titre, type, budget min/max
- **Tri**: Par titre, type, budget (ASC/DESC)
- **Pagination**: 10/20/50 éléments par page
- **Filtres**: Toggle type (POSITIF/NEGATIF)
- **Export**: CSV, Excel des imprévus

---

## 🔧 CRUD - CasRelles (Gestion Utilisateur)

### Routes
```
GET    /alea/cas                   → Liste mes cas réels
POST   /alea/cas/new               → Créer depuis formulaire
POST   /alea/cas/{id}/traiter      → Traiter un cas
POST   /alea/cas/{id}/annuler      → Annuler un cas
```

### Fonctionnalités Avancées
- **Simulation**: Appliquer l'impact sur les comptes
- **Statistiques**: Total positif/negatif par mois
- **Graphiques**: Courbe d'évolution
- **Filtres**: Par type, période, résultat

---

## 🧮 Métiers Avancés

### 1. Simulation d'Impact
```php
// Quand utilisateur choisit une solution
$casRelles->setSolution($solution);
$casRelles->setResultat('TRAITE');

// Appliquer l'impact
if ($casRelles->getType() === 'NEGATIF') {
    // Dépense - diminuer le solde
    $epargne->setSolde($epargne->getSolde() - $casRelles->getMontant());
} else {
    // Gain - augmenter le solde
    $epargne->setSolde($epargne->getSolde() + $casRelles->getMontant());
}
```

### 2. Score de Résilience
```php
// Calculer score: (Fonds Sécurité / Dépenses Mensuelles) * 100
$fondsSecurite = $user->getFondsSecurite();
$depensesMois = $this->getDepensesMois($user);
$score = ($fondsSecurite / max($depensesMois, 1)) * 100;
```

### 3. Recommandations IA
```php
// Basé sur l'historique
if ($score < 50) {
    return "Renforcez votre fonds de sécurité!";
}
if ($nbNegatifs > $nbPositifs) {
    return "Trop de dépenses imprévues recently.";
}
```

---

## 📊 DQL - Requêtes Avancées

### ImprevusRepository
```php
// Recherche avec LIKE
public function search(string $query): array
public function countByType(string $type): int
public function getStats(): array
```

### CasRellesRepository
```php
// Stats par utilisateur
public function getStatsByUser(User $user): array
public function getMonthlyEvolution(User $user, int $year): array
public function getTopDepenses(User $user, int $limit): array
```

---

## 🎨 Templates Admin (Bootstrap 5)

### Liste Imprevus
```twig
<table class="table table-hover">
    <thead>
        <tr>
            <th>{{ knp_pagination_sortable(pagination, 'Titre', 'i.titre') }}</th>
            <th>{{ knp_pagination_sortable(pagination, 'Type', 'i.type') }}</th>
            <th>{{ knp_pagination_sortable(pagination, 'Budget', 'i.budget') }}</th>
            <th>Actions</th>
        </tr>
    </thead>
    <!-- Recherche -->
    <form method="get">
        <input name="q" placeholder="Rechercher...">
        <select name="type">
            <option value="">Tous types</option>
            <option value="POSITIF">Positif</option>
            <option value="NEGATIF">Négatif</option>
        </select>
    </form>
</table>
```

---

## 📦 Dépendances à Installer

```bash
# Pagination & Tri
composer require knplabs/knp-paginator-bundle

# Export
composer require doctrine/dbal
composer require phpoffice/phpspreadsheet

# Chart.js pour les graphiques
# (via CDN dans base.html.twig)
```

---

## 🚀 Ordre de Implémentation

1. **AdminController** - CRUD Imprevus
2. **AdminTemplates** - Liste, New, Edit avec KnpPaginator
3. **CasRellesController** - Traitement des cas
4. **CasRellesService** - Métiers (impact, score, IA)
5. **DashboardStats** - Graphiques et statistiques
6. **Update Alea** - Intégrer les données dynamiques

