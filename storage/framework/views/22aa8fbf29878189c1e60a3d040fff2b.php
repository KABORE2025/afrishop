<?php $__env->startSection('titre', 'Mes produits'); ?>

<?php $__env->startSection('navigation'); ?>
    <?php echo $__env->make('vendeur._navigation', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('contenu'); ?>
<div x-data="ecranProduits" x-cloak>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-extrabold">Mes produits</h1>
        <button class="btn-primaire" @click="ouvrirCreation">Nouveau produit</button>
    </div>

    <template x-if="sansBoutique">
        <div class="note">
            <b>Votre candidature est en cours d'examen.</b>
            Vous pourrez ajouter des produits dès que votre boutique sera validée.
        </div>
    </template>

    <template x-if="erreur && !sansBoutique">
        <div class="carte border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="!chargement && !erreur && elements.length === 0">
        <div class="carte p-10 text-center text-sm text-gris">
            Aucun produit pour l'instant. Commencez par en ajouter un.
        </div>
    </template>

    <div class="space-y-3" x-show="elements.length > 0">
        <template x-for="p in elements" :key="p.id">
            <div class="carte p-4">

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-bold" x-text="p.nom"></p>
                        <p class="text-xs text-gris">
                            <span x-text="p.reference"></span> —
                            <span class="montant" x-text="fcfa(p.prix_ttc_cfa)"></span>
                            <span x-show="!p.actif" class="font-semibold text-gris"> — dépublié par vous</span>
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        
                        <span class="etat"
                              :class="{
                                'etat-reverse': p.moderation.statut === 'publie',
                                'etat-sequestre': p.moderation.statut === 'en_attente',
                                'etat-impaye': ['rejete','retire'].includes(p.moderation.statut),
                                'etat-rembourse': p.moderation.statut === 'brouillon',
                              }"
                              x-text="etiquetteModeration(p.moderation.statut)"></span>

                        <button class="btn-secondaire" @click="ouvrirEdition(p)">Modifier</button>
                    </div>
                </div>

                <p class="tuile-note" x-show="p.moderation.statut !== 'publie'"
                   x-text="p.moderation.explication"></p>
                <p class="tuile-note text-alerte" x-show="p.moderation.motif"
                   x-text="'Motif : ' + p.moderation.motif"></p>

                
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <template x-for="m in (p.medias ?? [])" :key="m.id">
                        <div class="relative">
                            <img :src="m.urls.miniature" :alt="m.texte_alternatif || p.nom"
                                 class="h-16 w-16 rounded-lg border border-bord object-cover">
                            
                            <span x-show="m.type === 'video'"
                                  class="absolute bottom-0 left-0 rounded-br-lg rounded-tl-lg bg-texte/80 px-1 text-[10px] font-bold text-white">
                                vidéo
                            </span>
                            <button type="button"
                                    class="absolute -right-1.5 -top-1.5 grid h-5 w-5 place-items-center rounded-full bg-alerte text-xs font-bold text-white"
                                    title="Supprimer" @click="supprimerMedia(p, m)">×</button>
                        </div>
                    </template>

                    <button type="button" class="btn-secondaire h-16" @click="ouvrirMedias(p)">
                        <span x-show="!(p.medias ?? []).length">Ajouter une photo</span>
                        <span x-show="(p.medias ?? []).length">+ Média</span>
                    </button>
                </div>

                
                <p class="tuile-note text-attente" x-show="!(p.medias ?? []).length">
                    Aucune photo. Une fiche sans image ne se vend pratiquement pas.
                </p>

                
                <div class="mt-3 overflow-x-auto">
                    <table class="tableau">
                        <thead>
                            <tr>
                                <th>Déclinaison</th>
                                <th class="text-right">Prix</th>
                                <th class="text-right">Stock</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="v in p.variantes" :key="v.id">
                                <tr>
                                    <td class="whitespace-nowrap">
                                        <span x-text="v.libelle"></span>
                                        <span class="block text-xs text-gris" x-text="v.sku"></span>
                                    </td>
                                    <td class="text-right montant" x-text="fcfa(v.prix_effectif_ttc_cfa)"></td>
                                    <td class="text-right montant font-semibold"
                                        :class="{ 'text-alerte': v.stock <= 0, 'text-attente': v.stock_critique }"
                                        x-text="v.stock"></td>
                                    <td class="text-right">
                                        <button class="btn-secondaire" @click="ouvrirStock(p, v)">Stock</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-gris" x-show="pagination">
        <span>Page <span x-text="pagination?.current_page"></span> sur <span x-text="pagination?.last_page"></span></span>
        <span class="flex gap-2">
            <button class="btn-secondaire" :disabled="pagination?.current_page <= 1"
                    @click="charger(pagination.current_page - 1)">Précédente</button>
            <button class="btn-secondaire" :disabled="pagination?.current_page >= pagination?.last_page"
                    @click="charger(pagination.current_page + 1)">Suivante</button>
        </span>
    </div>

    
    <div x-show="fiche.ouverte" class="fixed inset-0 z-20 overflow-y-auto bg-black/40 p-4" x-cloak>
        <div class="carte mx-auto my-6 w-full max-w-lg p-5">
            <h2 class="mb-4 text-lg font-bold" x-text="fiche.id ? 'Modifier le produit' : 'Nouveau produit'"></h2>

            <div class="space-y-3">
                <div>
                    <label class="libelle" for="p-nom">Nom</label>
                    <input id="p-nom" class="champ" maxlength="160" x-model="fiche.donnees.nom">
                    <span class="erreur-champ" x-show="err('nom')" x-text="err('nom')"></span>
                </div>

                <div>
                    <label class="libelle" for="p-cat">Catégorie</label>
                    <select id="p-cat" class="champ" x-model="fiche.donnees.categorie_id">
                        <option value="">— Choisir —</option>
                        <template x-for="c in categories" :key="c.id">
                            <option :value="c.id" x-text="(c.emoji ? c.emoji + ' ' : '') + c.nom"></option>
                        </template>
                    </select>
                    <span class="erreur-champ" x-show="err('categorie_id')" x-text="err('categorie_id')"></span>

                    
                    <p class="tuile-note text-attente" x-show="categorieReservee">
                        Catégorie réservée : la publication demande une autorisation au cas par cas.
                        <span x-text="categorieReservee?.note_reserve"></span>
                    </p>
                </div>

                <div>
                    <label class="libelle" for="p-desc">Description</label>
                    <textarea id="p-desc" class="champ" rows="3" maxlength="5000" x-model="fiche.donnees.description"></textarea>
                    <span class="erreur-champ" x-show="err('description')" x-text="err('description')"></span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="libelle" for="p-prix">Prix TTC (FCFA)</label>
                        <input id="p-prix" type="number" min="1" class="champ montant" x-model="fiche.donnees.prix_ttc_cfa">
                        <p class="tuile-note">Toujours toutes taxes comprises : la loi impose l'affichage TTC.</p>
                        <span class="erreur-champ" x-show="err('prix_ttc_cfa')" x-text="err('prix_ttc_cfa')"></span>
                    </div>
                    <div>
                        <label class="libelle" for="p-poids">Poids (g)</label>
                        <input id="p-poids" type="number" min="0" class="champ montant" x-model="fiche.donnees.poids_g">
                    </div>
                </div>

                
                <template x-if="!fiche.id">
                    <div>
                        <label class="libelle" for="p-stock">Stock initial</label>
                        <input id="p-stock" type="number" min="0" class="champ montant" x-model="fiche.stockInitial">
                        <p class="tuile-note">
                            Une déclinaison « Standard » est créée d'office. Vous pourrez en ajouter d'autres ensuite.
                        </p>
                    </div>
                </template>

                <template x-if="fiche.id">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" class="rounded border-bord text-brun focus:ring-brun-clair"
                               x-model="fiche.donnees.actif">
                        Visible en vitrine
                    </label>
                </template>
            </div>

            
            <div class="note mt-4" x-show="fiche.id && risqueRemoderation">
                Modifier le nom, la description ou la catégorie d'une fiche publiée la fait
                <b>repasser en validation</b> : elle ne sera plus visible en vitrine en attendant.
                Changer seulement le prix ne remet rien en file.
            </div>

            <div class="note mt-3" x-show="fiche.message" x-text="fiche.message"></div>

            <div class="mt-4 flex justify-end gap-2">
                <button class="btn-secondaire" @click="fiche.ouverte = false">Annuler</button>
                <button class="btn-primaire" :disabled="fiche.enCours" @click="enregistrer">
                    <span x-show="!fiche.enCours">Enregistrer</span>
                    <span x-show="fiche.enCours">Enregistrement…</span>
                </button>
            </div>
        </div>
    </div>

    
    <div x-show="med.p" class="fixed inset-0 z-20 overflow-y-auto bg-black/40 p-4" x-cloak>
        <div class="carte mx-auto my-6 w-full max-w-lg p-5">
            <h2 class="mb-1 text-lg font-bold">Ajouter un média</h2>
            <p class="mb-4 text-sm text-gris" x-text="med.p?.nom"></p>

            <div class="mb-3 flex gap-2">
                <button class="btn-secondaire flex-1" :class="med.type === 'image' && 'border-brun text-brun'"
                        @click="med.type = 'image'">Photo</button>
                <button class="btn-secondaire flex-1" :class="med.type === 'video' && 'border-brun text-brun'"
                        @click="med.type = 'video'">Vidéo</button>
            </div>

            <div class="mb-3">
                <label class="libelle" for="m-fichier">
                    <span x-show="med.type === 'image'">Photo — JPEG, PNG ou WebP, 8 Mo maximum</span>
                    <span x-show="med.type === 'video'">Vidéo — MP4, WebM ou MOV, 20 Mo maximum</span>
                </label>
                <input id="m-fichier" type="file" class="champ"
                       :accept="med.type === 'video' ? 'video/mp4,video/webm,video/quicktime' : 'image/jpeg,image/png,image/webp'"
                       @change="med.fichier = $event.target.files[0] ?? null">
                <p class="tuile-note" x-show="med.fichier"
                   x-text="'Sélectionné : ' + med.fichier.name + ' — ' + Math.round(med.fichier.size / 1024) + ' Ko'"></p>
            </div>

            
            <div class="mb-3" x-show="med.type === 'video'">
                <label class="libelle" for="m-poster">Image de couverture — obligatoire</label>
                <input id="m-poster" type="file" class="champ" accept="image/jpeg,image/png,image/webp"
                       @change="med.poster = $event.target.files[0] ?? null">
                <p class="tuile-note">
                    Sans couverture, le client devrait télécharger la vidéo pour savoir ce qu'elle montre.
                </p>
            </div>

            <div class="mb-3">
                <label class="libelle" for="m-alt">Description de l'image</label>
                <input id="m-alt" type="text" class="champ" maxlength="255" x-model="med.texte_alternatif"
                       placeholder="Ex. : savon de karité posé sur un pagne">
                <p class="tuile-note">
                    Affichée si l'image ne se charge pas — ce qui arrive souvent en réseau lent —
                    et lue par les logiciels pour aveugles.
                </p>
            </div>

            <div class="note mb-3" x-show="med.type === 'video'">
                <b>Une vidéo coûte cher à regarder.</b>
                Elle n'est pas compressée par la plateforme : ce que vous envoyez est ce que
                le client télécharge. Filmez court, et gardez la photo comme premier média.
            </div>

            <div class="note mb-3" x-show="med.message" x-text="med.message"></div>

            <div class="flex justify-end gap-2">
                <button class="btn-secondaire" @click="med.p = null">Fermer</button>
                <button class="btn-primaire" :disabled="med.enCours || !med.fichier" @click="envoyerMedia">
                    <span x-show="!med.enCours">Envoyer</span>
                    <span x-show="med.enCours">Envoi…</span>
                </button>
            </div>
        </div>
    </div>

    
    <div x-show="stock.v" class="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4" x-cloak>
        <div class="carte w-full max-w-md p-5">
            <h2 class="mb-1 text-lg font-bold">Stock</h2>
            <p class="mb-4 text-sm text-gris">
                <span x-text="stock.p?.nom"></span> — <span x-text="stock.v?.libelle"></span>
                — actuellement <b class="montant" x-text="stock.v?.stock"></b>
            </p>

            
            <div class="mb-3 flex gap-2">
                <button class="btn-secondaire flex-1" :class="stock.mode === 'mouvement' && 'border-brun text-brun'"
                        @click="stock.mode = 'mouvement'">J'ai reçu / vendu</button>
                <button class="btn-secondaire flex-1" :class="stock.mode === 'absolu' && 'border-brun text-brun'"
                        @click="stock.mode = 'absolu'">Après inventaire</button>
            </div>

            <template x-if="stock.mode === 'mouvement'">
                <div>
                    <label class="libelle" for="s-mvt">Variation (+ ou −)</label>
                    <input id="s-mvt" type="number" class="champ montant" x-model="stock.mouvement" placeholder="+20">
                    <p class="tuile-note">Recommandé si plusieurs personnes tiennent la boutique.</p>
                </div>
            </template>

            <template x-if="stock.mode === 'absolu'">
                <div>
                    <label class="libelle" for="s-abs">Quantité comptée</label>
                    <input id="s-abs" type="number" min="0" class="champ montant" x-model="stock.stock">
                    <p class="tuile-note">Remplace la valeur actuelle. À réserver aux inventaires.</p>
                </div>
            </template>

            <div class="mt-3">
                <label class="libelle" for="s-seuil">Seuil d'alerte</label>
                <input id="s-seuil" type="number" min="0" class="champ montant" x-model="stock.seuil_alerte">
            </div>

            <div class="note mt-3" x-show="stock.message" x-text="stock.message"></div>

            <div class="mt-4 flex justify-end gap-2">
                <button class="btn-secondaire" @click="stock.v = null">Annuler</button>
                <button class="btn-primaire" :disabled="stock.enCours" @click="enregistrerStock">Enregistrer</button>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('scripts'); ?>
<script type="module">
    const MODERATION = {
        brouillon: 'Brouillon', en_attente: 'En validation', publie: 'Publié',
        rejete: 'Refusé', retire: 'Retiré',
    };

    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranProduits', () => ({
            elements: [], pagination: null, categories: [],
            chargement: true, erreur: null, sansBoutique: false,

            fiche: { ouverte: false, id: null, donnees: {}, stockInitial: 0,
                     original: {}, erreurs: {}, message: null, enCours: false },
            stock: { p: null, v: null, mode: 'mouvement', mouvement: '', stock: '',
                     seuil_alerte: '', message: null, enCours: false },
            med: { p: null, type: 'image', fichier: null, poster: null,
                   texte_alternatif: '', message: null, enCours: false },

            async init() {
                if (!window.exigerConnexion('vendeur')) return;
                this.charger(1);
                this.chargerCategories();
            },

            async charger(page = 1) {
                this.chargement = true; this.erreur = null;
                try {
                    const r = await window.api.get(`/vendeur/produits?page=${page}`);
                    this.elements = r.data ?? [];
                    this.pagination = r.meta ?? null;
                } catch (e) {
                    this.sansBoutique = e.sansBoutique === true;
                    this.erreur = e.message;
                    this.elements = [];
                } finally { this.chargement = false; }
            },

            async chargerCategories() {
                try { this.categories = await window.api.get('/categories'); }
                catch { /* le formulaire signalera le champ manquant */ }
            },

            get categorieReservee() {
                const c = this.categories.find(c => String(c.id) === String(this.fiche.donnees.categorie_id));
                return c?.reservee ? c : null;
            },

            etiquetteModeration(s) { return MODERATION[s] ?? s; },

            err(champ) { return this.fiche.erreurs[champ]?.[0] ?? null; },

            ouvrirCreation() {
                this.fiche = {
                    ouverte: true, id: null,
                    donnees: { nom: '', description: '', categorie_id: '', prix_ttc_cfa: '', poids_g: '' },
                    stockInitial: 0, original: {}, erreurs: {}, message: null, enCours: false,
                };
            },

            ouvrirEdition(p) {
                this.fiche = {
                    ouverte: true, id: p.id,
                    donnees: {
                        nom: p.nom, description: p.description ?? '',
                        categorie_id: p.categorie_id, prix_ttc_cfa: p.prix_ttc_cfa,
                        poids_g: p.poids_g ?? '', actif: p.actif,
                    },
                    stockInitial: 0,
                    /* Copie de référence : sert à prévenir de la remise en
                     * modération AVANT l'envoi, pas après. */
                    original: { nom: p.nom, description: p.description ?? '', categorie_id: p.categorie_id,
                                statut: p.moderation.statut },
                    erreurs: {}, message: null, enCours: false,
                };
            },

            /*
             * Prévient AVANT l'envoi que la fiche va repasser en
             * validation. Reproduit la règle du serveur (nom,
             * description ou catégorie modifiés sur une fiche publiée) —
             * c'est une duplication assumée, parce qu'avertir après coup
             * ne sert plus à rien.
             */
            get risqueRemoderation() {
                const o = this.fiche.original;
                if (o.statut !== 'publie') return false;

                return ['nom', 'description', 'categorie_id'].some(
                    (c) => String(this.fiche.donnees[c] ?? '') !== String(o[c] ?? '')
                );
            },

            async enregistrer() {
                if (this.fiche.enCours) return;
                this.fiche.enCours = true;
                this.fiche.erreurs = {}; this.fiche.message = null;

                const corps = { ...this.fiche.donnees };
                for (const c of ['prix_ttc_cfa', 'poids_g', 'categorie_id']) {
                    corps[c] = corps[c] === '' ? null : Number(corps[c]);
                }

                try {
                    if (this.fiche.id) {
                        const r = await window.api.put(`/vendeur/produits/${this.fiche.id}`, corps);
                        if (r.remodere) this.fiche.message = r.information;
                    } else {
                        corps.variantes = [{ libelle: 'Standard', stock: Number(this.fiche.stockInitial || 0) }];
                        await window.api.post('/vendeur/produits', corps);
                        this.fiche.message = "Produit créé. Il part en validation avant d'apparaître en vitrine.";
                    }

                    await this.charger(this.pagination?.current_page ?? 1);

                    /* Fermeture différée quand il y a un message à lire :
                     * refermer aussitôt fait disparaître l'information la
                     * plus utile de l'écran. */
                    if (!this.fiche.message) this.fiche.ouverte = false;
                    else setTimeout(() => { this.fiche.ouverte = false; }, 3500);
                } catch (e) {
                    this.fiche.erreurs = e.erreurs ?? {};
                    this.fiche.message = e.message;
                } finally { this.fiche.enCours = false; }
            },

            ouvrirMedias(p) {
                this.med = { p, type: 'image', fichier: null, poster: null,
                             texte_alternatif: '', message: null, enCours: false };
            },

            async envoyerMedia() {
                if (this.med.enCours || !this.med.fichier) return;

                if (this.med.type === 'video' && !this.med.poster) {
                    this.med.message = "Une vidéo demande une image de couverture.";
                    return;
                }

                this.med.enCours = true;
                this.med.message = null;

                /* FormData, pas JSON : un fichier ne se sérialise pas.
                 * Le navigateur pose lui-même l'en-tête multipart. */
                const corps = new FormData();
                corps.append('type', this.med.type);
                corps.append('fichier', this.med.fichier);
                if (this.med.poster) corps.append('poster', this.med.poster);
                if (this.med.texte_alternatif) corps.append('texte_alternatif', this.med.texte_alternatif);

                try {
                    await window.api.fichier(`/vendeur/produits/${this.med.p.id}/medias`, corps);
                    await this.charger(this.pagination?.current_page ?? 1);
                    this.med.p = null;
                } catch (e) {
                    this.med.message = e.message;
                } finally { this.med.enCours = false; }
            },

            async supprimerMedia(p, m) {
                /* Confirmation explicite : la suppression efface aussi
                 * les fichiers sur le disque, elle ne se défait pas. */
                if (!confirm('Supprimer ce média ? Cette action est définitive.')) return;

                try {
                    await window.api.delete(`/vendeur/medias/${m.id}`);
                    await this.charger(this.pagination?.current_page ?? 1);
                } catch (e) {
                    alert(e.message);
                }
            },

            ouvrirStock(p, v) {
                this.stock = {
                    p, v, mode: 'mouvement', mouvement: '', stock: v.stock,
                    seuil_alerte: v.seuil_alerte, message: null, enCours: false,
                };
            },

            async enregistrerStock() {
                if (this.stock.enCours) return;
                this.stock.enCours = true; this.stock.message = null;

                /* Un seul des deux part : l'API refuse `stock` ET
                 * `mouvement` ensemble, et elle a raison de le refuser. */
                const corps = { seuil_alerte: Number(this.stock.seuil_alerte || 0) };
                if (this.stock.mode === 'mouvement') {
                    if (this.stock.mouvement === '') { this.stock.enCours = false; return; }
                    corps.mouvement = Number(this.stock.mouvement);
                } else {
                    corps.stock = Number(this.stock.stock);
                }

                try {
                    const r = await window.api.patch(`/vendeur/variantes/${this.stock.v.id}`, corps);
                    await this.charger(this.pagination?.current_page ?? 1);

                    /* L'alerte de rupture ou de survente vient du serveur :
                     * elle est plus fiable qu'un calcul refait ici. */
                    if (r.alerte) { this.stock.message = r.alerte; setTimeout(() => { this.stock.v = null; }, 3000); }
                    else this.stock.v = null;
                } catch (e) {
                    this.stock.message = e.message;
                } finally { this.stock.enCours = false; }
            },
        }));
    });
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH F:\Afrishop\Afrishop\3-Backend-laravel\resources\views/vendeur/produits.blade.php ENDPATH**/ ?>