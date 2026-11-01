<?php

declare(strict_types=1);

// Intitulés reformulés ; aucun texte normatif protégé n'est reproduit.
return [
    'name' => 'NIS2',
    'version' => '2022/2555',
    'publisher' => 'Union européenne',
    'description' => 'Pack de préparation fondé sur la directive publique, à adapter à sa transposition nationale et à son périmètre d’assujettissement.',
    'requirements' => [
        ['ART-20', 'Responsabilité des organes de direction', 'Gouvernance'],
        ['ART-21.2-A', 'Politiques d’analyse des risques et de sécurité', 'Risques'],
        ['ART-21.2-B', 'Gestion des incidents', 'Incidents'],
        ['ART-21.2-C', 'Continuité, sauvegardes et gestion de crise', 'Résilience'],
        ['ART-21.2-D', 'Sécurité de la chaîne d’approvisionnement', 'Tiers'],
        ['ART-21.2-E', 'Sécurité des acquisitions, développements et vulnérabilités', 'Cycle de vie'],
        ['ART-21.2-F', 'Évaluation de l’efficacité des mesures', 'Assurance'],
        ['ART-21.2-G', 'Hygiène cyber et formation', 'Sensibilisation'],
        ['ART-21.2-H-J', 'Cryptographie, ressources humaines, accès et MFA', 'Protection'],
        ['ART-23', 'Notification des incidents significatifs', 'Notification'],
        ['ART-21.2-H', 'Politiques de cryptographie et, le cas échéant, de chiffrement', 'Protection', 'ART-21.2-H-J'],
        ['ART-21.2-I', 'Sécurité des ressources humaines, contrôle d’accès et gestion des actifs', 'Protection', 'ART-21.2-H-J'],
        ['ART-21.2-J', 'Authentification multifacteur, communications sécurisées et communications d’urgence sécurisées', 'Protection', 'ART-21.2-H-J'],
        ['ART-21.3', 'Prise en compte des vulnérabilités propres à chaque fournisseur direct et de leurs pratiques', 'Tiers', 'ART-21.2-D'],
        ['ART-23.4-A', 'Alerte précoce sous 24 heures après la connaissance d’un incident significatif', 'Notification', 'ART-23'],
        ['ART-23.4-B', 'Notification de l’incident sous 72 heures avec évaluation initiale', 'Notification', 'ART-23'],
        ['ART-23.4-C', 'Rapport intermédiaire sur demande de l’autorité ou du CSIRT', 'Notification', 'ART-23'],
        ['ART-23.4-D', 'Rapport final au plus tard un mois après la notification de l’incident', 'Notification', 'ART-23'],
    ],
];
