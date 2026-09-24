@extends('layouts.app')

@section('titre', 'Commandes')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranCommandesAdmin" x-cloak>

    <h1 class="mb-1 text-2xl font-extrabold">Commandes</h1>
    <p class="mb-4 text-sm text-gris">
        Retrouver une commande à partir de ce que le client donne au support : sa référence ou son numéro de téléphone.
    </p>

    <form class="carte mb-4 flex flex-wrap items-end gap-3 p-4" @submit.prevent="rechercher">
        <div class="min-w-0 flex-1">
            <label class="libelle" for="f-q">Référence ou téléphone</label>
            <input id="f-q" class="champ w-full" x-model="q" placeholder="BF-CMD-2026-000123 ou 70 00 11 22" autocomplete="off">
        </div>
        <button class="btn-primaire" :disabled="q.trim().length < 3">Rechercher</button>
    </form>

    <template x-if="erreur">
        <div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="!chargement && recherchee && resultats.length === 0 && !erreur">
        <div class="carte p-10 text-center text-sm text-gris">Aucune commande ne correspond.</div>
    </template>

    {{-- ------------------------------------------------------------
         RÉSULTATS
         ------------------------------------------------------------ --}}
    <div class="carte mb-4 overflow-x-auto" x-show="!fiche && resultats.length > 0">
        <table class="tableau">
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Client</th>
                    <th>Boutique</th>
                    <th>Marchandise</th>
                    <th>Argent</th>
                    <th class="text-right">Net boutique</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="sc in resultats" :key="sc.id">
                    <tr class="cursor-pointer hover:bg-fond" @click="ouvrir(sc.id)">
                        <td class="whitespace-nowrap font-semibold">
                            <span x-text="sc.reference"></span>
                            <p class="text-xs font-normal text-gris" x-text="dateFr(sc.passee_le)"></p>
                        </td>
                        <td x-text="sc.client_nom"></td>
                        <td x-text="sc.boutique"></td>
                        <td><span class="statut" x-text="libelleStatut(sc.statut)"></span></td>
                        <td><span class="etat" :class="'etat-' + sc.etat_fonds" x-text="sc.etat_fonds_libelle"></span></td>
                        <td class="text-right montant" x-text="fcfa(sc.montant_net_cfa)"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- ------------------------------------------------------------
         FICHE
         ------------------------------------------------------------ --}}
    <template x-if="fiche">
        <div class="space-y-4">
            <button class="text-sm text-gris hover:text-brun" @click="fiche = null" x-show="resultats.length > 0">
                ← Retour aux résultats
            </button>

            <div class="carte p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-lg font-bold" x-text="fiche.reference"></p>
                        <p class="text-xs text-gris">
                            Commande <span x-text="fiche.commande"></span> —
                            <span x-text="fiche.boutique"></span> —
                            <span x-text="libelleMode(fiche.mode_livraison)"></span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="statut" x-text="libelleStatut(fiche.statut)"></span>
                        <span class="etat" :class="'etat-' + fiche.etat_fonds" x-text="fiche.etat_fonds_libelle"></span>
                    </div>
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-fond p-3 text-sm">
                        <p class="tuile-libelle">Client</p>
                        <p class="mt-1 font-semibold" x-text="fiche.client.nom"></p>
                        <p x-text="fiche.client.telephone"></p>
                        <p class="text-gris" x-text="[fiche.client.quartier, fiche.client.repere].filter(Boolean).join(' — ')"></p>
                    </div>
                    <div class="rounded-lg bg-fond p-3 text-sm">
                        <p class="tuile-libelle">Remise</p>
                        <template x-if="fiche.expedition">
                            <div class="mt-1">
                                <p>Expédition : <b x-text="libelleExpedition(fiche.expedition.statut)"></b></p>
                                <p x-show="fiche.expedition.livreur" x-text="'Livreur : ' + fiche.expedition.livreur"></p>
                                <p class="text-alerte" x-show="fiche.expedition.motif_echec" x-text="'Échec : ' + fiche.expedition.motif_echec"></p>
                            </div>
                        </template>
                        <p class="mt-1 text-gris" x-show="!fiche.expedition && fiche.mode_livraison !== 'retrait_boutique'">
                            Pas encore expédiée.
                        </p>
                        <p class="mt-1" x-show="fiche.mode_livraison === 'retrait_boutique'">Retrait au comptoir de la boutique.</p>
                    </div>
                </div>
            </div>

            {{-- CODE DE REMISE — suivi sans la valeur. --}}
            <div class="carte p-4">
                <h2 class="mb-3 font-bold" x-text="fiche.code.type === 'retrait' ? 'Code de retrait' : 'Code de livraison'"></h2>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="tuile">
                        <p class="tuile-libelle">État</p>
                        <p class="mt-1 font-semibold" x-text="libelleCode(fiche.code)"></p>
                        <p class="tuile-note" x-show="fiche.code.valide_le" x-text="'Le ' + dateHeure(fiche.code.valide_le)"></p>
                    </div>
                    <div class="tuile">
                        <p class="tuile-libelle">Saisies erronées</p>
                        <p class="mt-1 font-semibold" :class="fiche.code.tentatives >= 3 && 'text-alerte'" x-text="fiche.code.tentatives"></p>
                        <p class="tuile-note" x-show="fiche.code.tentatives >= 3">
                            Plusieurs échecs : le client dicte-t-il le bon code ? Vérifier avant de renvoyer.
                        </p>
                    </div>
                    <div class="tuile">
                        <p class="tuile-libelle">SMS au client</p>
                        <p class="mt-1 font-semibold" x-text="fiche.code.sms.length + ' envoi(s)'"></p>
                        <p class="tuile-note" x-show="dernierSms" x-text="dernierSms && ('Dernier : ' + libelleSms(dernierSms.statut))"></p>
                    </div>
                </div>

                <table class="tableau mt-3" x-show="fiche.code.sms.length > 0">
                    <thead><tr><th>Mis en file</th><th>Envoyé</th><th>Statut</th></tr></thead>
                    <tbody>
                        <template x-for="(s, i) in fiche.code.sms" :key="i">
                            <tr>
                                <td class="text-xs" x-text="dateHeure(s.cree_le)"></td>
                                <td class="text-xs" x-text="dateHeure(s.envoye_le)"></td>
                                <td>
                                    <span class="etat" :class="['echoue','rejete'].includes(s.statut) ? 'etat-impaye' : (s.statut === 'remis' ? 'etat-reverse' : 'etat-sequestre')"
                                          x-text="libelleSms(s.statut)"></span>
                                    <span class="text-xs text-alerte" x-show="s.erreur" x-text="s.erreur"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button class="btn-primaire" x-show="fiche.actions.peut_renvoyer_code" @click="ouvrirAction('renvoyer')">
                        Renvoyer le code par SMS
                    </button>
                    <button class="btn-secondaire" x-show="fiche.actions.peut_voir_code" @click="ouvrirAction('voir')">
                        Voir le code
                    </button>
                </div>

                <p class="tuile-note" x-show="codeAffiche === null && fiche.actions.peut_voir_code">
                    À réserver au support (client injoignable, téléphone perdu) et aux litiges. Chaque consultation est journalisée.
                </p>

                {{-- Le code consulté n'est affiché que dans cette fiche,
                     le temps de la session : il n'est ni mémorisé ni
                     remis dans la liste. --}}
                <div class="note mt-3" x-show="codeAffiche !== null">
                    Code : <b class="font-mono text-lg tracking-widest" x-text="codeAffiche"></b>
                    <button class="ml-3 text-xs text-gris hover:text-brun" @click="codeAffiche = null">Masquer</button>
                </div>
            </div>

            {{-- ARGENT --}}
            <div class="carte p-4">
                <h2 class="mb-3 font-bold">Argent</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="tuile">
                        <p class="tuile-libelle">Payé par le client</p>
                        <p class="mt-1 font-semibold montant" x-text="fcfa(fiche.montant_client_cfa)"></p>
                    </div>
                    <div class="tuile">
                        <p class="tuile-libelle">Net pour la boutique</p>
                        <p class="mt-1 font-semibold montant" x-text="fcfa(fiche.montant_net_cfa)"></p>
                    </div>
                    <div class="tuile">
                        <p class="tuile-libelle">Libération prévue</p>
                        <p class="mt-1 font-semibold" x-text="fiche.fonds.liberation_prevue_le ? dateHeure(fiche.fonds.liberation_prevue_le) : '—'"></p>
                    </div>
                </div>
                <p class="note mt-3" x-show="fiche.fonds.raison">
                    <b x-text="fiche.fonds.liberable ? 'Libérable.' : 'Pourquoi les fonds ne partent pas :'"></b>
                    <span x-text="' ' + fiche.fonds.raison"></span>
                </p>
                <div class="mt-3" x-show="fiche.actions.peut_ouvrir_litige">
                    <button class="btn-secondaire" @click="ouvrirAction('litige')">Ouvrir un litige pour le client</button>
                    <p class="tuile-note">
                        Quand le client signale un problème au support au lieu de sa page de commande.
                        Les fonds sont gelés jusqu'à l'arbitrage.
                    </p>
                </div>
            </div>

            {{-- RETOUR DE COLIS --}}
            <div class="carte border-alerte/40 p-4" x-show="fiche.actions.peut_cloturer_retour">
                <h2 class="mb-1 font-bold">Colis en retour vers la boutique</h2>
                <p class="mb-3 text-sm text-gris">
                    Si la boutique ne clôture pas alors que le colis lui est revenu (constat, photo, appel),
                    vous pouvez le faire à sa place : le client est remboursé et le stock est rendu.
                </p>
                <button class="btn-danger" @click="ouvrirAction('retour')">Clôturer le retour</button>
            </div>

            {{-- LITIGES & RETOURS --}}
            <div class="carte p-4 text-sm" x-show="fiche.litiges.length || fiche.retours.length">
                <template x-for="l in fiche.litiges" :key="l.reference">
                    <p>Litige <b x-text="l.reference"></b> — <span x-text="l.statut"></span>
                        <a href="/console/litiges" class="ml-2 text-brun hover:underline" x-show="['ouvert','en_examen'].includes(l.statut)">Arbitrer →</a></p>
                </template>
                <template x-for="rt in fiche.retours" :key="rt.reference">
                    <p>Retour <b x-text="rt.reference"></b> — <span x-text="rt.type + ', ' + rt.statut"></span></p>
                </template>
            </div>

            {{-- HISTORIQUE --}}
            <div class="carte p-4">
                <h2 class="mb-3 font-bold">Historique</h2>
                <ol class="space-y-2 text-sm">
                    <template x-for="(e, i) in fiche.historique" :key="i">
                        <li class="flex flex-wrap gap-x-3">
                            <span class="w-36 shrink-0 text-xs text-gris" x-text="dateHeure(e.le)"></span>
                            <span class="font-semibold" x-text="libelleEvenement(e.type)"></span>
                            <span class="text-xs text-gris" x-text="'(' + e.auteur_type + ')'"></span>
                            <span class="w-full text-xs text-gris" x-show="e.motif" x-text="'Motif : ' + e.motif"></span>
                        </li>
                    </template>
                </ol>
            </div>
        </div>
    </template>

    {{-- ------------------------------------------------------------
         ACTION MOTIVÉE — renvoyer, voir, clôturer
         ------------------------------------------------------------ --}}
    <div x-show="act.type" class="fixed inset-0 z-20 overflow-y-auto bg-black/40 p-4" x-cloak>
        <div class="carte mx-auto my-6 w-full max-w-lg p-5">
            <h2 class="mb-1 text-lg font-bold" x-text="{ renvoyer: 'Renvoyer le code', voir: 'Voir le code', retour: 'Clôturer le retour', litige: 'Ouvrir un litige' }[act.type]"></h2>
            <p class="mb-3 text-sm text-gris" x-text="fiche?.reference"></p>

            <div class="note mb-3" x-show="act.type === 'renvoyer'">
                Le <b>même</b> code est renvoyé au <span x-text="fiche?.client.telephone"></span>. Aucun nouveau code
                n'est généré : celui que le client a peut-être déjà transmis à un proche reste valable.
            </div>
            <div class="note mb-3" x-show="act.type === 'voir'">
                Le code s'affichera ici. Ne le communiquez <b>jamais</b> au vendeur ni au livreur : c'est la
                preuve que le client a bien reçu son colis, et elle libère l'argent de la boutique.
            </div>
            <div class="note mb-3" x-show="act.type === 'retour'">
                <b>Le client sera remboursé</b> et le stock rendu à la boutique. Cette opération ne se défait pas.
            </div>

            <template x-if="act.type === 'litige'">
                <div>
                    <div class="note mb-3">
                        Les fonds de la boutique seront <b>gelés</b> et elle sera prévenue par SMS pour donner sa version.
                    </div>
                    <label class="libelle" for="a-lmotif">Problème signalé</label>
                    <select id="a-lmotif" class="champ mb-3" x-model="act.litigeMotif">
                        <option value="non_recu">Colis non reçu</option>
                        <option value="endommage">Colis endommagé</option>
                        <option value="non_conforme">Produit non conforme</option>
                        <option value="incomplet">Commande incomplète</option>
                        <option value="contrefacon">Soupçon de contrefaçon</option>
                        <option value="autre">Autre</option>
                    </select>
                    <label class="libelle" for="a-ldesc">Ce que rapporte le client</label>
                    <textarea id="a-ldesc" class="champ" rows="4" x-model="act.motif"
                              placeholder="Ex. : appel du 24/09 — le client indique que l'écran était fissuré à l'ouverture."></textarea>
                    <p class="tuile-note">Au moins vingt caractères. La boutique lira ce texte.</p>
                </div>
            </template>

            <template x-if="act.type !== 'litige'">
                <div>
                    <label class="libelle" for="a-motif">Motif — journalisé</label>
                    <textarea id="a-motif" class="champ" rows="3" x-model="act.motif"
                              placeholder="Ex. : client injoignable, appel au support du 24/09, SMS jamais reçu."></textarea>
                    <p class="tuile-note">Au moins dix caractères.</p>
                </div>
            </template>

            <div class="note mt-3" x-show="act.message" x-text="act.message"></div>

            <div class="mt-3 flex justify-end gap-2">
                <button class="btn-secondaire" @click="act.type = null">Annuler</button>
                <button :class="act.type === 'retour' ? 'btn-danger' : 'btn-primaire'"
                        :disabled="act.enCours || act.motif.trim().length < (act.type === 'litige' ? 20 : 10)" @click="executer">
                    <span x-show="!act.enCours">Confirmer</span>
                    <span x-show="act.enCours">Traitement…</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
    const STATUTS = {
        a_preparer: 'À préparer', prete: 'Prête', expediee: 'Expédiée',
        livree: 'Livrée', retournee: 'Retournée', annulee: 'Annulée',
    };
    const EXPEDITIONS = {
        prevue: 'prévue', en_cours: 'en cours', livree: 'livrée',
        echouee: 'échouée', retour_expediteur: 'retour vers la boutique',
    };
    const MODES = { domicile: 'Livraison à domicile', point_relais: 'Point relais', retrait_boutique: 'Retrait en boutique' };
    const SMS = { en_file: 'En file', envoye: 'Envoyé', remis: 'Remis', echoue: 'Échoué', rejete: 'Rejeté' };
    const EVENEMENTS = {
        creee: 'Commande créée', paiement_encaisse: 'Paiement encaissé', ecart_encaissement: 'Écart d’encaissement',
        expediee: 'Expédiée — code envoyé', prete_pour_retrait: 'Prête pour retrait — code envoyé',
        livree: 'Livrée (code validé)', retiree_en_boutique: 'Retirée en boutique (code validé)',
        livraison_echouee: 'Échec de livraison', refus_a_la_porte: 'Refus à la porte',
        retour_expediteur_declare: 'Retour vers la boutique', retour_recu: 'Retour reçu',
        fonds_liberes: 'Fonds libérés', rembourse: 'Client remboursé', annulee_non_payee: 'Annulée sans paiement',
        litige_arbitre: 'Litige arbitré', litige_ouvert: 'Litige ouvert', litige_reponse_boutique: 'Réponse de la boutique au litige',
        reception_confirmee: 'Réception confirmée par le client', code_consulte: 'Code consulté par l’admin',
        code_renvoye: 'Code renvoyé par l’admin', remis_au_transitaire: 'Remis au transitaire', vente_comptoir: 'Vente comptoir',
    };

    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranCommandesAdmin', () => ({
            q: '', resultats: [], recherchee: false, fiche: null,
            chargement: false, erreur: null, codeAffiche: null,
            act: { type: null, motif: '', litigeMotif: 'non_conforme', enCours: false, message: null },

            init() {
                if (!window.exigerConnexion()) return;
                // Lien direct depuis l'écran Séquestre : /console/commandes?id=42
                const id = new URLSearchParams(location.search).get('id');
                if (id) this.ouvrir(id);
            },

            get dernierSms() { return this.fiche?.code.sms.at(-1) ?? null; },

            async rechercher() {
                this.chargement = true; this.erreur = null; this.fiche = null;
                try {
                    const r = await window.api.get(`/admin/sous-commandes?q=${encodeURIComponent(this.q.trim())}`);
                    this.resultats = r.data ?? [];
                    this.recherchee = true;
                    if (this.resultats.length === 1) this.ouvrir(this.resultats[0].id);
                } catch (e) { this.erreur = e.message; this.resultats = []; }
                finally { this.chargement = false; }
            },

            async ouvrir(id) {
                this.chargement = true; this.erreur = null; this.codeAffiche = null;
                try { this.fiche = await window.api.get(`/admin/sous-commandes/${id}`); }
                catch (e) { this.erreur = e.message; }
                finally { this.chargement = false; }
            },

            ouvrirAction(type) { this.act = { type, motif: '', litigeMotif: 'non_conforme', enCours: false, message: null }; },

            async executer() {
                if (this.act.enCours) return;   // un double-clic enverrait deux SMS
                this.act.enCours = true; this.act.message = null;
                const id = this.fiche.id, motif = this.act.motif.trim();
                try {
                    if (this.act.type === 'voir') {
                        const r = await window.api.get(`/admin/sous-commandes/${id}/code-remise?motif=${encodeURIComponent(motif)}`);
                        this.codeAffiche = r.code;
                    } else if (this.act.type === 'litige') {
                        await window.api.post(`/admin/sous-commandes/${id}/litiges`, {
                            motif: this.act.litigeMotif, description: motif,
                        });
                    } else if (this.act.type === 'renvoyer') {
                        await window.api.post(`/admin/sous-commandes/${id}/code-remise/renvoyer`, { motif });
                    } else {
                        await window.api.post(`/admin/sous-commandes/${id}/cloturer-retour`, { motif });
                    }
                    const code = this.codeAffiche;
                    this.act.type = null;
                    await this.ouvrir(id);          // l'historique montre la trace de l'action
                    this.codeAffiche = code;
                } catch (e) { this.act.message = e.message; }
                finally { this.act.enCours = false; }
            },

            libelleStatut(s) { return STATUTS[s] ?? s; },
            libelleExpedition(s) { return EXPEDITIONS[s] ?? s; },
            libelleMode(m) { return MODES[m] ?? m; },
            libelleSms(s) { return SMS[s] ?? s; },
            libelleEvenement(t) { return EVENEMENTS[t] ?? t; },
            libelleCode(c) {
                if (!c.genere) return 'Pas encore généré';
                return c.valide ? 'Validé' : 'En attente de remise';
            },
            dateHeure(iso) {
                if (!iso) return '—';
                return new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            },
        }));
    });
</script>
@endpush
