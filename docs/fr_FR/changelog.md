# Changelog

## 0.3.0

- Widget : Marche/Arrêt et Bip coupé affichés en un seul interrupteur (au lieu de deux).
  Les équipements existants sont renommés automatiquement (noms par défaut uniquement).
- Nouvelle info « Statut commande » : en attente, confirmée ou non confirmée par la clim
  (firmware ≥ 0.5.0, qui renvoie automatiquement une commande non confirmée).

## 0.2.0

- Ventilation : ajout de la vitesse « Silence » et libellés alignés sur la
  télécommande Airton (Vitesse 1 à 4, Ventilation max, Méga-turbo).
- Les listes de valeurs des commandes existantes sont resynchronisées à
  l'enregistrement de l'équipement.
- Contrat MQTT mis à jour avec les observations faites sur une vraie clim.

## 0.1.0

- Première version : démon MQTT embarqué, découverte automatique des modules
  ESPAC, commandes complètes du contrat MQTT v1, page équipement avec les
  informations du module.
