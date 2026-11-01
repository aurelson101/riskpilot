<?php

declare(strict_types=1);

// Intitulés reformulés ; aucun texte normatif protégé n'est reproduit.
return [
    'name' => 'TISAX / VDA ISA',
    'version' => '6',
    'publisher' => 'ENX Association / VDA',
    'description' => 'Structure des chapitres du catalogue VDA ISA 6 (sécurité de l’information, protection des prototypes, protection des données) avec des intitulés reformulés, pour préparer une évaluation TISAX AL2 ou AL3. Les questions de contrôle et critères de maturité ne sont pas reproduits : importez le catalogue officiel avec l’import CSV.',
    'requirements' => [
        ['CH-1', 'Politiques et organisation de la sécurité de l’information', 'Sécurité de l’information'],
        ['1.1', 'Politiques de sécurité de l’information', 'Sécurité de l’information', 'CH-1'],
        ['1.2', 'Organisation de la sécurité de l’information', 'Sécurité de l’information', 'CH-1'],
        ['1.3', 'Gestion des actifs', 'Sécurité de l’information', 'CH-1'],
        ['1.4', 'Gestion des risques de sécurité de l’information', 'Sécurité de l’information', 'CH-1'],
        ['1.5', 'Évaluations', 'Sécurité de l’information', 'CH-1'],
        ['1.6', 'Gestion des incidents et des crises', 'Sécurité de l’information', 'CH-1'],
        ['CH-2', 'Ressources humaines', 'Sécurité de l’information'],
        ['2.1', 'Sécurité liée au personnel', 'Sécurité de l’information', 'CH-2'],
        ['CH-3', 'Sécurité physique et continuité d’activité', 'Sécurité de l’information'],
        ['3.1', 'Sécurité physique et continuité', 'Sécurité de l’information', 'CH-3'],
        ['CH-4', 'Gestion des identités et des accès', 'Sécurité de l’information'],
        ['4.1', 'Gestion des identités et des accès', 'Sécurité de l’information', 'CH-4'],
        ['CH-5', 'Sécurité informatique et cybersécurité', 'Sécurité de l’information'],
        ['5.1', 'Cryptographie', 'Sécurité de l’information', 'CH-5'],
        ['5.2', 'Sécurité d’exploitation', 'Sécurité de l’information', 'CH-5'],
        ['5.3', 'Sécurité des communications et des systèmes', 'Sécurité de l’information', 'CH-5'],
        ['CH-6', 'Relations avec les fournisseurs', 'Sécurité de l’information'],
        ['6.1', 'Gestion des fournisseurs', 'Sécurité de l’information', 'CH-6'],
        ['CH-7', 'Conformité', 'Sécurité de l’information'],
        ['7.1', 'Conformité réglementaire et contractuelle', 'Sécurité de l’information', 'CH-7'],
        ['CH-8', 'Protection des prototypes', 'Protection des prototypes'],
        ['8.1', 'Sécurité physique et environnementale', 'Protection des prototypes', 'CH-8'],
        ['8.2', 'Exigences organisationnelles', 'Protection des prototypes', 'CH-8'],
        ['8.3', 'Manipulation des véhicules, composants et pièces', 'Protection des prototypes', 'CH-8'],
        ['8.4', 'Exigences pour les véhicules d’essai', 'Protection des prototypes', 'CH-8'],
        ['8.5', 'Exigences pour les événements et prises de vue', 'Protection des prototypes', 'CH-8'],
        ['CH-9', 'Protection des données', 'Protection des données'],
        ['9.1', 'Politiques et organisation de la protection des données', 'Protection des données', 'CH-9'],
        ['9.2', 'Traitement des données personnelles', 'Protection des données', 'CH-9'],
    ],
];
