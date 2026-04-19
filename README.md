# IA CRYPTO INVEST — Guide d'installation

## Structure des fichiers

```
ia_crypto_invest/
├── index.php          ← Interface principale (dashboard)
├── api.php            ← Handler AJAX (toutes les actions)
├── functions.php      ← Library core (DB, Mistral, market, agents)
├── cron_update.php    ← Cron : mise à jour marché + brain cycle
├── schema.sql         ← Schéma complet des 4 bases SQLite
├── .htaccess          ← Config LiteSpeed/Hostinger
└── db/                ← Dossier créé automatiquement
    ├── main.db        ← Users, agents, coins, news, analyses
    ├── short_term.db  ← Ticks, OHLCV 1m/5m, signaux scalping
    ├── medium_term.db ← OHLCV 1h/4h/1d, indicateurs techniques
    └── long_term.db   ← Fondamentaux, thèses, historique daily
```

## Installation sur Hostinger

1. **Upload tous les fichiers** dans votre dossier public_html (ou sous-dossier)

2. **Créez le dossier db/** et assurez-vous qu'il est writable :
   ```
   mkdir db
   chmod 755 db
   ```

3. **Premier accès** : Visitez `index.php` — les BDD et les 10 agents par défaut
   sont créés automatiquement.

4. **Cron (recommandé)** : Dans le panel Hostinger, ajoutez un cron :
   - Chaque minute : `php /home/u170902479/public_html/ia_crypto_invest/cron_update.php`
   - Ou URL cron : `https://votresite.com/ia_crypto_invest/cron_update.php`

## Configuration API

Les clés Mistral sont dans `functions.php` — constante `MISTRAL_KEYS`.

## Sources de données

- **CoinGecko** : Top 100 cryptos en EUR avec sparklines 7j
- **Binance** : Tickers 24h pour prix live
- **Google News RSS** : Actualités par coin
- **CoinDesk/CoinTelegraph RSS** : Flux crypto généraux

## Fonctionnalités

### Marché
- Top 100 cryptos avec mise à jour auto toutes les 60s
- Sparklines 7 jours par coin
- Tri par rank, prix, variation, volume, market cap
- Recherche en temps réel

### Agents IA (Cerveau Central)
- 10 agents pré-créés au démarrage
- Création d'agents en langage naturel
- Apprentissage par renforcement : archivage des mauvais, création des meilleurs
- Toujours 100 agents actifs maintenus
- Décisions de trading simulées avec Mistral

### Analyse IA
- Score sentiment (-10 à +10) par coin
- Facteurs haussiers/baissiers détectés
- Recommandation short/medium/long terme
- Articles de presse analysés automatiquement

### Comptes utilisateur
- Inscription/connexion (email + bcrypt)
- Capital virtuel de 1 000 000 € par compte
- Sessions PHP sécurisées

## Modèles Mistral utilisés

| Tâche | Modèle |
|-------|--------|
| Décisions agents | mistral-small-2506 |
| Analyse news | magistral-medium-2509 |
| Cerveau Central | mistral-large-2512 |
| Génération agents | mistral-large-2512 |
| Leçons archivage | ministral-8b-2512 |
| Contexte large | mistral-small-2603 |

## Performance

- SQLite WAL mode sur toutes les BDD
- Index sur toutes les colonnes fréquentes
- Rotation automatique des clés Mistral
- sleep(1) entre appels API (rate limit free tier)
- Timeout 120s par appel Mistral
