# IA CRYPTO INVEST v2.0 - Système de Trading Autonome IA

## Architecture inspirée des meilleurs systèmes de trading IA autonomes

### 🚀 NOUVELLES FONCTIONNALITÉS

#### 1. **Rotation Intelligente de 3 API Keys Mistral**
- **Load Balancing pondéré**: 50%/30%/20% du trafic
- **Health Check dynamique**: Score de santé 0-100 basé sur:
  - Taux d'erreur
  - Latence moyenne
  - Rate limits rencontrés
- **Retry automatique** avec backoff exponentiel
- **Fallback simulation** si toutes les clés sont dégradées

```bash
# Configuration requise (variables d'environnement)
export MISTRAL_API_KEY_1="votre_premiere_cle"
export MISTRAL_API_KEY_2="votre_deuxieme_cle"
export MISTRAL_API_KEY_3="votre_troisieme_cle"
```

#### 2. **Reinforcement Learning Avancé**
- **Mémoire épisodique**: Stocke state/action/reward dans SQLite
- **Q-Learning simplifié**: Estimation des valeurs d'actions
- **Apprentissage par l'expérience**: Réutilise les décisions gagnantes
- **Mise à jour des scores** de renforcement des agents

#### 3. **Évolution Génétique des Stratégies**
- **Sélection naturelle**: Archive les agents sous-performants (< -5%)
- **Extraction de leçons**: IA analyse les erreurs passées
- **Création évolutive**: Combine meilleures stratégies + évite erreurs
- **Généations successives**: Tracking parent/enfant

#### 4. **Cache Intelligent**
- **Double couche**: Mémoire + Fichier JSON
- **TTL configurable**: Expiration automatique
- **Réduction appels API**: Cache réponses marché 60s

#### 5. **Architecture Orientée Objet**
- **Classes modulaires**: Engine, ApiRotation, Database, Brain, AgentManager, etc.
- **Injection de dépendances**: Meilleure testabilité
- **Singleton pattern**: Instance unique de l'Engine

---

## 📁 Structure des Fichiers

```
/workspace/
├── src/
│   └── Core.php           # Nouveau moteur v2.0 (1290 lignes)
├── api_v2.php             # API endpoint compatible v2
├── cron_v2.php            # Cron runner v2
├── index.php              # Interface web (inchangée)
├── functions.php          # Ancien système (gardé pour compatibilité)
├── db/                    # Bases SQLite (créé automatiquement)
├── cache/                 # Cache API et stats
└── logs/                  # Logs système
```

---

## 🔧 Installation

### 1. Configurer les 3 API Keys Mistral

```bash
# Dans votre .env ou variables serveur
export MISTRAL_API_KEY_1="mk-your-first-key-here"
export MISTRAL_API_KEY_2="mk-your-second-key-here"
export MISTRAL_API_KEY_3="mk-your-third-key-here"
```

### 2. Initialiser le système

```bash
cd /workspace
php -r "require 'src/Core.php'; IACrypto\Core\Engine::getInstance();"
```

### 3. Lancer le cron (toutes les 30 secondes)

```bash
# Ajouter au crontab
*/1 * * * * cd /workspace && php cron_v2.php >> logs/cron.log 2>&1

# OU tester manuellement
php cron_v2.php
```

---

## 🎯 Algorithmes Implémentés

### Rotation API - Weighted Random + Health Filter

```php
// Sélectionne une clé selon: poids × santé
effective_weight = weight × (health / 100)

// Ajustements dynamiques:
- Succès: health += 2
- Échec: health -= 10
- Rate limit: health -= 40
```

### Reinforcement Learning - Q-Learning Simplifié

```php
// Stocke expérience
storeExperience(state, action, reward, next_state)

// Calcule valeur Q
Q(state, action) = avg(reward) × discount_factor

// Exploration ε-greedy
if (rand < exploration_rate) {
    action = random()  // Exploration
} else {
    action = argmax(Q(state))  // Exploitation
}
```

### Évolution Génétique

```
1. Sélectionner top 5 agents par PnL%
2. Archiver bottom 20% avec extraction leçons IA
3. Prompt IA: "Crée N agents combinant meilleures stratégies + évitant erreurs"
4. Nouvelle génération avec parent_ids tracking
```

---

## 📊 API Endpoints

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `api_v2.php?action=stats` | GET | Statistiques système |
| `api_v2.php?action=coins` | GET | Liste cryptos (50 top) |
| `api_v2.php?action=agents` | GET | Agents actifs triés par PnL |
| `api_v2.php?action=logs&limit=50` | GET | Logs console temps réel |
| `api_v2.php?action=brain_cycle` | POST | Force cycle cerveau |
| `api_v2.php?action=api_stats` | GET | Stats des 3 API keys |
| `api_v2.php?action=market_update` | GET | Force update marché |

---

## 🧠 Hyperparamètres Configurables

Dans `src/Core.php`:

```php
define('TARGET_AGENTS', 50);           // Nombre optimal d'agents
define('RL_LEARNING_RATE', 0.15);      // Taux apprentissage
define('RL_DISCOUNT_FACTOR', 0.9);     // Discount futur rewards
define('RL_EXPLORATION_RATE', 0.2);    // ε-greedy exploration
define('MIN_CONFIDENCE_TRADE', 65);    // Seuil confiance trade
define('TRADE_INTERVAL_SECONDS', 8);   // Cooldown entre trades
define('BRAIN_CYCLE_SECONDS', 30);     // Cycle cerveau
```

---

## 🔄 Migration depuis v1.0

Le nouveau système est **rétro-compatible**:

```php
// Anciennes fonctions toujours disponibles
initDatabases();      // → Engine::getInstance()
getSystemStats();     // → Engine::getInstance()->getSystemStats()
runBrainCycle();      // → Engine::getInstance()->runBrainCycle()
```

Les anciennes tables SQLite sont réutilisées. Une nouvelle table `rl_memory` est ajoutée pour le Reinforcement Learning.

---

## 📈 Monitoring

### Dashboard Web
L'interface `index.php` fonctionne sans changement avec le nouveau moteur.

### Stats API Keys
```bash
curl "http://localhost/api_v2.php?action=api_stats"
```

Response:
```json
[
  {
    "name": "primary",
    "health": 98,
    "requests": 1523,
    "errors": 12,
    "avg_latency": 245.3,
    "status": "excellent"
  },
  ...
]
```

---

## 🛡️ Gestion des Erreurs

- **Retry automatique** (3 tentatives max)
- **Backoff exponentiel** (500ms entre retries)
- **Mode simulation** si aucune API disponible
- **Lock file** pour éviter exécutions cron simultanées
- **Logs détaillés** dans `logs/` et console web

---

## 💡 Bonnes Pratiques

1. **Rotatez vos API keys** régulièrement sur Mistral AI dashboard
2. **Surveillez les quotas** via `api_stats` endpoint
3. **Ajustez RL_EXPLORATION_RATE** selon maturité système (commencez à 0.2, réduisez à 0.05)
4. **Archivez manuellement** les agents trop risqués via SQL si besoin
5. **Backup régulier** du dossier `db/`

---

## 🚀 Prochaines Améliorations Possibles

- [ ] File d'attente Redis pour décisions asynchrones
- [ ] WebSocket pour logs temps réel push
- [ ] Backtesting historique sur données archivées
- [ ] A/B testing de stratégies
- [ ] Export performances vers CSV/PDF
- [ ] Notifications Telegram/Discord sur trades importants

---

**Développé avec ❤️ pour le trading crypto autonome**
*Compatible PHP 8.0+ • SQLite 3 • Mistral AI API*
