# IA Crypto Invest - Système de Trading Autonome

## Architecture

Ce système utilise une architecture PHP/SQLite optimisée pour Hostinger avec:

- **3 API Keys Mistral en rotation** pour contourner les limites du free tier
- **Reinforcement Learning** avec mémoire épisodique
- **AJAX Polling** au lieu de cron (compatible hébergement mutualisé)
- **Interface temps réel** magnifique et animée

## Installation

1. Configurez vos 3 clés API Mistral dans les variables d'environnement:
   ```
   MISTRAL_API_KEY_1=votre_cle_1
   MISTRAL_API_KEY_2=votre_cle_2
   MISTRAL_API_KEY_3=votre_cle_3
   ```

2. Assurez-vous que les dossiers `db/`, `cache/`, `logs/` sont accessibles en écriture

3. Ouvrez `index.php` dans votre navigateur

## Fonctionnement

- Le frontend appelle `api.php` toutes les 3 secondes via AJAX
- Un cycle cerveau est déclenché toutes les 10 secondes
- Les agents IA prennent des décisions d'achat/vente chaque minute
- L'IA centrale analyse le marché et améliore les stratégies

## API Endpoints

- `api.php?action=stats` - Statistiques du système
- `api.php?action=coins` - Liste des cryptos
- `api.php?action=agents` - Agents actifs
- `api.php?action=trades` - Historique des trades
- `api.php?action=positions` - Positions ouvertes
- `api.php?action=logs` - Journal d'activité
- `api.php?action=run_cycle` - Déclencher un cycle cerveau

## Limites Hostinger Contournées

- Pas de cron → AJAX polling depuis le frontend
- Timeout limité → Requêtes asynchrones
- SQLite optimisé avec WAL mode
- Rotation API pour éviter rate limiting
