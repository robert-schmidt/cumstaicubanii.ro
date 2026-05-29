const FAQ_ITEMS = [
  {
    q: 'Cât de corecte / valide sunt datele de pe site?',
    a: 'Strict atât de corecte cât și-au dorit utilizatorii să fie. Avem un sistem automat care marchează valorile evident exagerate (peste 5× mediana pe tip) și un proces manual de moderare în spate. Nu putem însă filtra minciuna în limite plauzibile — câteva sume umflate dispar în mulțime, cu cât sunt mai multe răspunsuri cu atât distorsiunea se diluează.',
  },
  {
    q: 'De ce folosiți mediana și nu media?',
    a: 'Există o glumă veche printre statisticieni: când Bill Gates intră într-un bar, în medie toți cei dinăuntru devin miliardari — dar mediana nu se clintește, nimeni n-a câștigat în realitate nimic. Asta e problema mediei: o singură valoare extremă o trage în sus și ascunde realitatea. Dacă într-un grup de 10 oameni unul declară 10 milioane RON și restul câte 50.000, media iese ~1 milion — o cifră în care nu se regăsește nimeni. Mediana e valoarea de la mijloc când îi pui pe toți în ordine: jumătate au mai puțin, jumătate au mai mult. E utilizatorul „tipic", mult mai aproape de ce ai întâlni dacă întrebi un om la întâmplare. Pentru date financiare, unde câțiva oameni cu averi mari (sau câteva sume umflate) pot să tragă media în sus, mediana e singura cifră onestă.',
  },
  {
    q: 'De ce nu validați prin email sau SMS?',
    a: 'Ținem anonimitatea sus de tot. Un email confirmat n-ar face datele mai veridice — știi cât de parșivi și persuasivi au devenit brokerii de credite? În plus, ne-ar îngreuna și share-uitul rezultatelor.',
  },
  {
    q: 'Bun, dar de ce există site-ul ăsta?',
    a: 'Pentru că m-am pus într-o seară să-mi calculez datoriile totale și mi-a ieșit ~130k EUR. Mi-a venit să întreb: cum li se pare altora suma asta? Alții cât au? Așa a apărut tot ce vezi aici.',
  },
  {
    q: 'Ce înseamnă "asset" mai exact?',
    a: 'Orice bun care poate fi convertit relativ ușor în bani: depozite, imobile, terenuri, acțiuni, crypto, bunuri de valoare. NU e asset venitul recurent (salariu, pensie) — acela e un flux, nu un stoc de avere.',
  },
  {
    q: 'Ce e codul de sesiune din colțul de jos?',
    a: 'E un cod scurt (8 caractere, fără 0/1/o/l/i ca să fie ușor de citit) generat pentru tine la submit. Cu el te poți întoarce pe dashboard de pe alt dispozitiv sau după ce ștergi cookies. Click pe el → se copiază în clipboard.',
  },
  {
    q: 'Cine vede datele mele?',
    a: 'Doar tu, prin codul tău de sesiune. Restul lumii vede doar agregate — medii, mediane, distribuții. Nu se expun rânduri individuale. Codul nu e legat de email, nume, IP — nici eu, ca admin, nu pot urmări înapoi cine ești.',
  },
];

export default function FAQ() {
  return (
    <div className="space-y-2">
      {FAQ_ITEMS.map((item, i) => (
        <details
          key={i}
          className="group rounded-xl border border-slate-200 bg-white open:bg-slate-50/50 transition"
        >
          <summary className="cursor-pointer list-none px-4 py-3 flex items-center justify-between gap-3 text-sm font-medium text-slate-800 select-none">
            <span>{item.q}</span>
            <svg
              width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"
              className="text-slate-400 shrink-0 transition-transform group-open:rotate-180"
            >
              <path d="M5 8l5 5 5-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </summary>
          <p className="px-4 pb-4 text-sm text-slate-600 leading-relaxed">
            {item.a}
          </p>
        </details>
      ))}
    </div>
  );
}
