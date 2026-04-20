# IA CRYPTO INVEST - Système de Trading Autonome par IA

## 🚀 Description

Système complet de trading automatique de cryptomonnaies utilisant une **IA Centrale** (Cerveau) qui crée et gère des **Agents IA spécialisés** avec des stratégies différentes.

### Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    CERVEAU CENTRAL (IA)                      │
│  - Analyse le marché en temps réel                           │
│  - Crée de nouveaux agents basés sur les meilleurs performers│
│  - Archive les agents sous-performants                       │
│  - Applique du Reinforcement Learning continu                │
│  - Distribue le capital aux agents                           │
└─────────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
        ▼                     ▼                     ▼
┌───────────────┐   ┌───────────────┐   ┌───────────────┐
│  Agent APEX   │   │  Agent NOVA   │   │  Agent ZEUS   │
│  Scalping     │   │  Swing        │   │  Long Term    │
│  BTC/ETH      │   │  Support/Res  │   │  Fundamentals │
└───────────────┘   └───────────────┘   └───────────────┘
        │                     │                     │
        └─────────────────────┼─────────────────────┘
                              ▼
                    ┌─────────────────┐
                    │   EXÉCUTION     │
                    │   Trades réels  │
                    │   BRICS Coins   │
                    └─────────────────┘
```

## 💰 Monnaie : BRICS Coin

- **1 BRICS Coin = 1 Euro** (valeur virtuelle pour simulation)
- Capital initial : **1,000,000 BRICS** (1M EUR)
- Tous les trades sont exécutés en BRICS Coins

## 🔧 Installation

### 1. Prérequis

- Serveur PHP 7.4+ avec SQLite3
- Accès à l'API Mistral AI (optionnel, mode simulation inclus)
- Cron job pour l'exécution automatique

### 2. Configuration API Mistral (Optionnel)

Pour utiliser la vraie IA, configurez votre clé API:

```bash
export MISTRAL_API_KEY="sk-proj-votre-clé-mistral-ici"
```

Ou modifiez directement `functions.php`:
```php
define('MISTRAL_API_KEY', 'sk-proj-votre-clé-mistral-ici');
```

**Sans clé API**, le système fonctionne en **mode simulation** avec des réponses IA générées localement.

### 3. Initialisation de la Base de Données

Les bases de données SQLite sont créées automatiquement au premier lancement:
- `db/main.db` - Utilisateurs, agents, trades, configurations
- `db/short_term.db` - Données temps réel (scalping)
- `db/medium_term.db` - Données swing trading
- `db/long_term.db` - Investissements long terme

### 4. Configuration Cron

Pour un trading automatique, ajoutez au crontab:

```bash
# Mise à jour marché et cycle cerveau toutes les minutes
* * * * * php /path/to/cron_update.php >> /var/log/ia_crypto.log 2>&1

# Cycle de trade toutes les 8 secondes (via script dédié ou AJAX browser)
```

## 🎯 Fonctionnement

### Cycle de Trading (toutes les 8 secondes)

1. **Récupération des cours** depuis CoinGecko API
2. **Analyse marché** par chaque agent actif
3. **Décision de trade** (buy/sell/hold) avec confiance %
4. **Exécution réelle** du trade en BRICS Coins
5. **Mise à jour P&L** et statistiques

### Cycle Cerveau Central (toutes les 30 secondes)

1. **Analyse performance** de tous les agents
2. **Archive** les agents avec PnL < -5% (après 5 trades minimum)
3. **Extraction leçons** des échecs (via IA)
4. **Création nouveaux agents** basés sur:
   - Stratégies des top performers
   - Leçons des agents archivés
   - Diversité des timeframes
5. **Distribution capital** aux nouveaux agents

### Types d'Agents

| Type | Timeframe | Stratégie | Risque |
|------|-----------|-----------|--------|
| Scalping | 1-15 min | Mouvements rapides | Élevé |
| Momentum | 15min-4h | Suit les tendances | Moyen |
| Swing | 4h-1j | Supports/Résistances | Moyen |
| DCA | 1j-1sem | Achat progressif | Faible |
| Long Term | 1sem+ | Fondamentaux | Faible |

## 📊 Interface Web

Accédez à `index.php` pour voir:

- **Console IA** : Logs en temps réel des décisions
- **Statistiques** : Capital, PnL, win rate, nombre d'agents
- **Top Cryptos** : Prix et variations en direct
- **Agents Actifs** : Performance de chaque agent
- **Meilleur Performer** : Agent du moment

### Bouton "Cycle IA"

Force l'exécution immédiate d'un cycle cerveau complet.

## 📈 Statistiques Clés

- **Capital Total** : 1,000,000 BRICS
- **Capital Pool** : Fonds non alloués
- **Capital Agents** : Fonds chez les agents
- **PnL Total** : Profit/perte cumulé
- **Win Rate** : % de trades gagnants
- **Agents Actifs** : Nombre d'agents en activité

## 🔒 Sécurité

- Erreurs PHP loguées mais non affichées en production
- Bases de données en WAL mode pour performance
- Protection contre exécutions concurrentes (lock file)
- Validation des inputs API

## 🛠️ Dépannage

### Le site ne charge pas
1. Vérifiez que le dossier `db/` existe et est writable
2. Consultez les logs erreur PHP
3. Exécutez manuellement: `php cron_update.php`

### Pas de trades exécutés
1. Vérifiez que des agents existent (table `agents`)
2. Contrôlez les logs console dans l'interface
3. En mode simulation, les trades sont aléatoires

### API Mistral ne répond pas
1. Vérifiez votre clé API
2. Testez avec: `curl -H "Authorization: Bearer VOTRE_CLE" https://api.mistral.ai/v1/models`
3. Le mode simulation prend le relais automatiquement

## 📝 Fichiers Principaux

| Fichier | Rôle |
|---------|------|
| `index.php` | Interface utilisateur |
| `api.php` | Endpoints API JSON |
| `functions.php` | Logique métier complète |
| `cron.php` | Cycle de trade (8s) |
| `cron_update.php` | Mise à jour marché + cerveau |
| `schema.sql` | Structure database |

## 🎯 Objectif

Maximiser le profit en BRICS Coins grâce à:
- La diversification des stratégies d'agents
- L'apprentissage continu par reinforcement
- L'adaptation dynamique aux conditions de marché
- L'archivage intelligent des stratégies perdantes

---

**⚠️ Attention** : Ceci est un système de **simulation**. Les BRICS Coins n'ont pas de valeur réelle. Utilisez uniquement à des fins éducatives et de test.
