"""Golden payslips for NONOX Avtal (A12): expected amounts worked out here,
independently of the PHP engine, from the agreement data and the 2026 premium
rules. Writes tests/Fixtures/agreements/payslips/<agreement>.json."""
import json, os, sys
from decimal import Decimal as D, ROUND_HALF_UP

OUT = sys.argv[1]
IBB = 8_340_000
PBB = 5_920_000
T75 = IBB * 75 // 120          # 7.5 IBB / 12 = 5 212 500
CAP_PBB = PBB * 75 // 120      # 7.5 PBB / 12 = 3 700 000
CEIL_ITP = IBB * 30 // 12      # 30 IBB / 12 = 20 850 000
MEAL_VALUE = 12_400            # Skatteverket lunch/middag 2026 (test rule)


def hu(x):
    return int(D(x).quantize(D(1), rounding=ROUND_HALF_UP))


def saf_lo(basis, age):
    if age < 22 or age >= 65:
        return 0
    return hu((D(min(basis, T75)) * 450 + D(max(0, basis - T75)) * 3000) / 10000)


def itp1(basis, age):
    if age < 25:
        return 0
    return hu((D(min(basis, T75)) * 450 + D(max(0, min(basis, CEIL_ITP) - T75)) * 3000) / 10000)


def fora_arbetare(basis, hang=False):
    return hu(D(basis) * 1 / 10000) + hu(D(min(basis, CAP_PBB)) * 10 / 10000) + hu(D(min(basis, CAP_PBB)) * (74 if hang else 34) / 10000)


def fora_tjm(basis, age, hang=False):
    trr = hu(D(basis) * (80 if hang else 40) / 10000) if age < 67 else 0
    return hu(D(basis) * 2 / 10000) + trr


def percent_rate(hourly, bp):
    return hu(D(hourly) * bp / 10000)


ADULT = {"birth_date": "1990-06-15", "start": "2020-01-01"}       # 35 at the end of May 2026
YOUNG = {"birth_date": "2008-03-10", "start": "2025-01-01"}       # 18 at the end of May 2026


def emp(base, **kw):
    e = {"salary_form": "MONTHLY", "monthly_salary_ore": None, "hourly_rate_ore": None, "employment_rate_bp": 10000, "weekly_hours": "40", "group": None, "experience_from": None}
    e.update(base)
    e.update(kw)
    return e


def case(name, file, employment, inputs, expected, period="2026-05", payment="2026-05-25", absences=None, vacation=None):
    c = {"name": name, "agreement": file, "employment": employment, "period": period, "payment_date": payment, "inputs": inputs, "expected": expected}
    if absences:
        c["absences"] = absences
    if vacation:
        c["vacation"] = vacation
    return c


def inp(code, qty, date):
    return {"code": code, "quantity": qty, "date": date}


def week(start_day, n=5, hours="8"):
    return [{"type": "VACATION", "date": f"2026-05-{start_day + i:02d}", "hours": hours} for i in range(n)]


def arbetare_costs(basis, age, hang=False):
    return {"TJANSTEPENSION": saf_lo(basis, age), "AVTALSFORSAKRING": fora_arbetare(basis, hang)}


fixtures = {}

# ---------------------------------------------------------------- Gröna Riks
GR = "visita-hrf-grona-riks/MEMBER-2025-04-01.json"
GRH = "visita-hrf-grona-riks/HANGAVTAL-2025-04-01.json"
S = 3_200_000
h = D(S) / 173
gr = []
gr.append(case("dag: månadslön utan avtalsrader", GR, emp(ADULT, monthly_salary_ore=S, group="2"), [], {"lines": {"MANADSLON": S}, "gross_ore": S, "employer": arbetare_costs(S, 35), "warnings": []}))
g = S + 10 * 2759
gr.append(case("kväll: OB 10 tim vardag efter 20", GR, emp(ADULT, monthly_salary_ore=S, group="2"), [inp("GR_OB", "10", "2026-05-05")], {"lines": {"GR_OB": 27_590}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
g = S + 5 * 2759 + 5 * 2431
gr.append(case("natt: OB och natt-OB 01–06", GR, emp(ADULT, monthly_salary_ore=S, group="2"), [inp("GR_OB", "5", "2026-05-06"), inp("GR_OB_NATT", "5", "2026-05-06")], {"lines": {"GR_OB": 13_795, "GR_OB_NATT": 12_155}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
g = S + 8 * 2759
gr.append(case("helg: OB lördag efter 16", GR, emp(ADULT, monthly_salary_ore=S, group="2"), [inp("GR_OB", "8", "2026-05-09")], {"lines": {"GR_OB": 22_072}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
r = percent_rate(h, 10000)
g = S + 8 * r
gr.append(case("storhelg: 100 % av timlönen, varnar när julafton inte är lördag eller söndag", GR, emp(ADULT, monthly_salary_ore=S, group="2"), [inp("GR_STORHELG", "8", "2026-12-24")],
               {"lines": {"GR_STORHELG": 8 * r}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": ["men 2026-12-24 är inte en sådan dag"]}, period="2026-12", payment="2026-12-23"))
gr.append(case("ung: 18 år med timlön under minimilönen, ingen pension före 22", GR, emp(YOUNG, salary_form="HOURLY", hourly_rate_ore=11_000, group="2U"), [{"code": "TIMLON", "quantity": "100", "date": "2026-05-04"}],
               {"lines": {"TIMLON": 1_100_000}, "gross_ore": 1_100_000, "employer": arbetare_costs(1_100_000, 18), "warnings": ["under minimilönen"]}))
pt = D(S) * 7500 / 10000 / (D(30) * D("4.3"))
r = percent_rate(pt, 13500)
g = S * 3 // 4 + 2 * r
gr.append(case("deltid 75 %: övertid a) med timlön enligt deltidsformeln", GR, emp(ADULT, monthly_salary_ore=S, employment_rate_bp=7500, weekly_hours="30", group="2"), [inp("GR_OT_A", "2", "2026-05-12")],
               {"lines": {"GR_OT_A": 2 * r}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
rb = percent_rate(h, 17000)
rc = percent_rate(h, 19000)
g = S + 3 * rb + 2 * rc
gr.append(case("övertid b) och c)", GR, emp(ADULT, monthly_salary_ore=S, group="2"), [inp("GR_OT_B", "3", "2026-05-13"), inp("GR_OT_C", "2", "2026-05-14")],
               {"lines": {"GR_OT_B": 3 * rb, "GR_OT_C": 2 * rc}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
basis = 38_400_000
per_day = D(basis) * 1272 / 10000 / 25
pay = hu(per_day * 5)
deduction = hu(D(40) * (D(S) * 12 / 52 / 40))
g = S - deduction + pay
gr.append(case("semester: 12,72 % enligt avtalet, procentregeln", GR, emp(ADULT, monthly_salary_ore=S, group="2"), [], {"lines": {"SEMESTERLON": pay, "SEMESTERAVDRAG": -deduction}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []},
               absences=week(18), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": basis}))
g = 1_100_000 + 2 * 126_900
gr.append(case("semester: lägsta semesterlön per dag under 20 år", GR, emp(YOUNG, salary_form="HOURLY", hourly_rate_ore=12_500, group="2U"), [{"code": "TIMLON", "quantity": "88", "date": "2026-05-04"}],
               {"lines": {"SEMESTERLON": 253_800, "TIMLON": 1_100_000}, "gross_ore": g, "employer": arbetare_costs(g, 18), "warnings": []},
               absences=week(18, 2), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": 2_000_000}))
g = S + 20 * MEAL_VALUE - 20 * 5600
gr.append(case("kostavdrag: 20 dagar, förmånen minskas med avdraget", GR, emp(ADULT, monthly_salary_ore=S, group="2"), [inp("GR_KOST", "20", "2026-05-04")],
               {"lines": {"FORMAN_EGENBETALNING": -112_000, "KOSTFORMAN_LUNCH": 248_000, "OVRIG_FORMAN": -112_000}, "gross_ore": g, "employer": arbetare_costs(S, 35), "warnings": []}))
g = S
gr.append(case("hängavtal: högre omställningspremie och administrationsavgift 0,51 %", GRH, emp(ADULT, monthly_salary_ore=S, group="2"), [],
               {"lines": {"MANADSLON": S}, "gross_ore": g, "employer": {"TJANSTEPENSION": saf_lo(S, 35), "AVTALSFORSAKRING": fora_arbetare(S, True) + hu(D(S) * 51 / 10000)}, "warnings": []}))
fixtures["visita-hrf-grona-riks"] = gr

# ---------------------------------------------------------------- Detaljhandel
DH = "svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json"
S = 3_000_000
h = D(S) / 166
dh = []
dh.append(case("dag", DH, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [], {"lines": {"MANADSLON": S}, "gross_ore": S, "employer": arbetare_costs(S, 35), "warnings": []}))
for name, code, bp, qty, date in [("kväll: OB 50 % 18.15–20", "DH_OB_50", 5000, "8", "2026-05-05"), ("natt: OB 70 % efter 20", "DH_OB_70", 7000, "6", "2026-05-06"), ("helg: OB 100 % lördag efter 12", "DH_OB_100", 10000, "6", "2026-05-09"), ("helgdag: OB 100 % Kristi himmelsfärd", "DH_OB_100", 10000, "8", "2026-05-14")]:
    r = percent_rate(h, bp)
    g = S + int(qty) * r
    dh.append(case(name, DH, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [inp(code, qty, date)], {"lines": {code: int(qty) * r}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
r = percent_rate(15_000, 5000)
g = 80 * 15_000 + 6 * r
dh.append(case("ung: 18 år timlön under minimilönen, OB på egen timlön", DH, emp(YOUNG, salary_form="HOURLY", hourly_rate_ore=15_000, group="ALLA"), [{"code": "TIMLON", "quantity": "80", "date": "2026-05-04"}, inp("DH_OB_50", "6", "2026-05-07")],
               {"lines": {"TIMLON": 1_200_000, "DH_OB_50": 6 * r}, "gross_ore": g, "employer": arbetare_costs(g, 18), "warnings": ["under minimilönen"]}))
r = percent_rate(h, 13500)
g = S * 3 // 4 + 3 * r
dh.append(case("deltid 75 %: övertid de första timmarna (timlön + 35 %)", DH, emp(ADULT, monthly_salary_ore=S, employment_rate_bp=7500, weekly_hours="28.69", group="ALLA"), [inp("DH_OT_A", "3", "2026-05-12")],
               {"lines": {"DH_OT_A": 3 * r}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
r70 = percent_rate(h, 17000)
r100 = percent_rate(h, 20000)
g = S + 2 * r70 + 2 * r100
dh.append(case("övertid 70 % och 100 %", DH, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [inp("DH_OT_70", "2", "2026-05-13"), inp("DH_OT_100", "2", "2026-05-16")],
               {"lines": {"DH_OT_70": 2 * r70, "DH_OT_100": 2 * r100}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
basis = 36_000_000
pay = hu(D(basis) * 1300 / 10000 / 25 * 5)
deduction = hu(D(40) * (D(S) * 12 / 52 / 40))
g = S - deduction + pay
dh.append(case("semester: 13 % procentregeln", DH, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [], {"lines": {"SEMESTERLON": pay, "SEMESTERAVDRAG": -deduction}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []},
               absences=week(18), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": basis}))
g = 1_100_000 + 2 * 151_500
dh.append(case("semester: garantibelopp per dag för fyllda 18", DH, emp(YOUNG, salary_form="HOURLY", hourly_rate_ore=16_100, group="ALLA"), [{"code": "TIMLON", "quantity": "60", "date": "2026-05-04"}],
               {"lines": {"SEMESTERLON": 303_000}, "gross_ore": 966_000 + 303_000, "employer": arbetare_costs(966_000 + 303_000, 18), "warnings": []},
               absences=week(18, 2), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": 2_000_000}))
sup = hu(D(S) * 43 / 10000 * 5)
dh.append(case("semester: varnar när anställningen har sammalöneregeln men avtalet procentregeln", DH, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [],
               {"lines": {"SEMESTERTILLAGG": sup}, "gross_ore": S + sup, "employer": arbetare_costs(S + sup, 35), "warnings": ["anger procentregeln för månadsavlönade"]},
               absences=week(18), vacation={"rule": "SAMMALONEREGELN", "paid_days_earned": 25, "basis_ore": 36_000_000}))
fixtures["svenskhandel-handels-detaljhandel"] = dh

# ---------------------------------------------------------------- Lager och E-handel
LE = "svenskhandel-handels-lager-ehandel/MEMBER-2025-04-01.json"
S = 3_100_000
h = D(S) / 174
le = []
le.append(case("dag", LE, emp(ADULT, monthly_salary_ore=S, group="LAGER"), [], {"lines": {"MANADSLON": S}, "gross_ore": S, "employer": arbetare_costs(S, 35), "warnings": []}))
for name, code, bp, qty, date in [("morgon: OB 40 % 06–07", "LE_OB_40", 4000, "5", "2026-05-04"), ("kväll: OB 40 % 18–23", "LE_OB_40", 4000, "10", "2026-05-05"), ("natt: OB 70 % 23–06", "LE_OB_70", 7000, "7", "2026-05-06"), ("helg: OB 40 % lördag", "LE_OB_40", 4000, "8", "2026-05-09"), ("helgdag: OB 100 % Kristi himmelsfärd", "LE_OB_100", 10000, "8", "2026-05-14")]:
    r = percent_rate(h, bp)
    g = S + int(qty) * r
    le.append(case(name, LE, emp(ADULT, monthly_salary_ore=S, group="LAGER"), [inp(code, qty, date)], {"lines": {code: int(qty) * r}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
r = percent_rate(15_500, 7000)
g = 100 * 15_500 + 4 * r
le.append(case("ung: 18 år timlön under minimilönen med natt-OB", LE, emp(YOUNG, salary_form="HOURLY", hourly_rate_ore=15_500, group="LAGER"), [{"code": "TIMLON", "quantity": "100", "date": "2026-05-04"}, inp("LE_OB_70", "4", "2026-05-07")],
               {"lines": {"TIMLON": 1_550_000, "LE_OB_70": 4 * r}, "gross_ore": g, "employer": arbetare_costs(g, 18), "warnings": ["under minimilönen"]}))
r = percent_rate(h, 4000)
g = S * 3 // 4 + 6 * r
le.append(case("deltid 75 %: OB på heltidslönens timlön (deltidsformeln är inte data)", LE, emp(ADULT, monthly_salary_ore=S, employment_rate_bp=7500, weekly_hours="30", group="LAGER"), [inp("LE_OB_40", "6", "2026-05-11")],
               {"lines": {"LE_OB_40": 6 * r}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
basis = 37_200_000
pay = hu(D(basis) * 1300 / 10000 / 25 * 5)
deduction = hu(D(40) * (D(S) * 12 / 52 / 40))
g = S - deduction + pay
le.append(case("semester: 13 %", LE, emp(ADULT, monthly_salary_ore=S, group="LAGER"), [], {"lines": {"SEMESTERLON": pay, "SEMESTERAVDRAG": -deduction}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []},
               absences=week(18), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": basis}))
le.append(case("semester: garantibelopp för fyllda 18 (21 år ger högre)", LE, emp(YOUNG, salary_form="HOURLY", hourly_rate_ore=16_400, group="LAGER"), [{"code": "TIMLON", "quantity": "60", "date": "2026-05-04"}],
               {"lines": {"SEMESTERLON": 303_000}, "gross_ore": 60 * 16_400 + 303_000, "employer": arbetare_costs(60 * 16_400 + 303_000, 18), "warnings": []},
               absences=week(18, 2), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": 2_000_000}))
fixtures["svenskhandel-handels-lager-ehandel"] = le

# ---------------------------------------------------------------- Handelns tjänstemannaavtal
HT = "svenskhandel-unionen-tjansteman/MEMBER-2025-05-01.json"
S = 3_800_000
ht = []


def tjm_costs(basis, age):
    return {"TJANSTEPENSION": itp1(basis, age), "AVTALSFORSAKRING": fora_tjm(basis, age)}


ht.append(case("dag: ITP 1 och tjänstemännens försäkringar", HT, emp(ADULT, monthly_salary_ore=S, group="UNIONEN"), [], {"lines": {"MANADSLON": S}, "gross_ore": S, "employer": tjm_costs(S, 35), "warnings": []}))
for name, code, div, qty, date in [("kväll: förskjuten tid /600", "HT_FT_600", 600, "6", "2026-05-05"), ("natt: förskjuten tid /400", "HT_FT_400", 400, "5", "2026-05-06"), ("helg: förskjuten tid /300 lördag", "HT_FT_300", 300, "8", "2026-05-09"),
                                   ("helgdag: /300 Kristi himmelsfärd", "HT_FT_HELG_300", 300, "8", "2026-05-14"), ("storhelg: /150 pingstafton", "HT_FT_150", 150, "8", "2026-05-23"),
                                   ("övertid /94", "HT_OT_94", 94, "4", "2026-05-12"), ("övertid /72", "HT_OT_72", 72, "3", "2026-05-16")]:
    r = hu(D(S) / div)
    g = S + int(qty) * r
    ht.append(case(name, HT, emp(ADULT, monthly_salary_ore=S, group="UNIONEN"), [inp(code, qty, date)], {"lines": {code: int(qty) * r}, "gross_ore": g, "employer": tjm_costs(g, 35), "warnings": []}))
sup = hu(D(S) * 80 / 10000 * 5)
ht.append(case("semester: sammalöneregeln med 0,8 % per dag", HT, emp(ADULT, monthly_salary_ore=S, group="UNIONEN"), [], {"lines": {"SEMESTERTILLAGG": sup}, "gross_ore": S + sup, "employer": tjm_costs(S + sup, 35), "warnings": []},
               absences=week(18), vacation={"rule": "SAMMALONEREGELN", "paid_days_earned": 25, "basis_ore": 45_600_000}))
S2 = 6_000_000
ht.append(case("över 7,5 inkomstbasbelopp: 30 % ITP 1 över gränsen", HT, emp(ADULT, monthly_salary_ore=S2, group="UNIONEN"), [], {"lines": {"MANADSLON": S2}, "gross_ore": S2, "employer": tjm_costs(S2, 35), "warnings": []}))
ht.append(case("ung: 18 år, ingen ITP 1 före 25", HT, emp(YOUNG, monthly_salary_ore=2_500_000, group="UNIONEN"), [], {"lines": {"MANADSLON": 2_500_000}, "gross_ore": 2_500_000, "employer": tjm_costs(2_500_000, 18), "warnings": []}))
fixtures["svenskhandel-unionen-tjansteman"] = ht

# ---------------------------------------------------------------- Visita–Unionen
VU = "visita-unionen/MEMBER-2025-06-01.json"
S = 3_500_000
vu = []
vu.append(case("dag", VU, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [], {"lines": {"MANADSLON": S}, "gross_ore": S, "employer": tjm_costs(S, 35), "warnings": []}))
for name, code, ore, qty, date in [("kväll: OB efter 20", "VU_OB", 2668, "6", "2026-05-05"), ("helg: OB lördag efter 16", "VU_OB", 2668, "8", "2026-05-09")]:
    g = S + int(qty) * ore
    vu.append(case(name, VU, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [inp(code, qty, date)], {"lines": {code: int(qty) * ore}, "gross_ore": g, "employer": tjm_costs(g, 35), "warnings": []}))
g = S + 5 * 2668 + 5 * 2350
vu.append(case("natt: OB och natt-OB", VU, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [inp("VU_OB", "5", "2026-05-06"), inp("VU_OB_NATT", "5", "2026-05-06")], {"lines": {"VU_OB": 13_340, "VU_OB_NATT": 11_750}, "gross_ore": g, "employer": tjm_costs(g, 35), "warnings": []}))
for name, code, div, qty, date in [("jour vardag /600", "VU_JOUR", 600, "10", "2026-05-12"), ("beredskap storhelg /350", "VU_BERED_STORH", 350, "12", "2026-05-14"), ("övertid /94", "VU_OT_94", 94, "2", "2026-05-13"), ("restid /240", "VU_RESTID", 240, "3", "2026-05-20")]:
    r = hu(D(S) / div)
    g = S + int(qty) * r
    vu.append(case(name, VU, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [inp(code, qty, date)], {"lines": {code: int(qty) * r}, "gross_ore": g, "employer": tjm_costs(g, 35), "warnings": []}))
sup = hu(D(S) * 80 / 10000 * 5)
vu.append(case("semester: sammalöneregeln med 0,8 % per dag", VU, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [], {"lines": {"SEMESTERTILLAGG": sup}, "gross_ore": S + sup, "employer": tjm_costs(S + sup, 35), "warnings": []},
               absences=week(18), vacation={"rule": "SAMMALONEREGELN", "paid_days_earned": 25, "basis_ore": 42_000_000}))
g = S + 20 * MEAL_VALUE - 20 * 5500
vu.append(case("kostavdrag 55 kr per dag före 1 juni 2026", VU, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [inp("VU_KOST", "20", "2026-05-04")],
               {"lines": {"FORMAN_EGENBETALNING": -110_000, "KOSTFORMAN_LUNCH": 248_000}, "gross_ore": g, "employer": tjm_costs(S, 35), "warnings": []}))
vu.append(case("ung: 18 år, ingen ITP 1", VU, emp(YOUNG, monthly_salary_ore=2_500_000, group="ALLA"), [inp("VU_OB", "4", "2026-05-05")],
               {"lines": {"VU_OB": 10_672}, "gross_ore": 2_510_672, "employer": tjm_costs(2_510_672, 18), "warnings": []}))
fixtures["visita-unionen"] = vu

# ---------------------------------------------------------------- Bageri
BG = "livsmedelsforetagen-livs-bageri/MEMBER-2025-04-01.json"
H = 18_000
bg = []


def bg_case(name, inputs, lines, extra=0, warnings=None, employment=None, **kw):
    hours = 160
    base = hours * (employment or {}).get("hourly_rate_ore", H)
    g = base + sum(lines.values()) + extra
    age = 18 if employment and employment.get("birth_date") == YOUNG["birth_date"] else 35
    return case(name, BG, employment or emp(ADULT, salary_form="HOURLY", hourly_rate_ore=H, group="BAGERI"), [{"code": "TIMLON", "quantity": str(hours), "date": "2026-05-04"}] + inputs,
                {"lines": lines, "gross_ore": g, "employer": arbetare_costs(g, age), "warnings": warnings or []}, **kw)


bg.append(bg_case("dag: timlön", [], {}))
bg.append(bg_case("natt och kväll mån–tor: OB 1", [inp("BG_OB_1", "10", "2026-05-05")], {"BG_OB_1": 10 * 5089}))
bg.append(bg_case("fredag kväll: OB 2", [inp("BG_OB_2", "6", "2026-05-08")], {"BG_OB_2": 6 * 9887}))
bg.append(bg_case("helg: OB 3 lördag dag", [inp("BG_OB_3", "8", "2026-05-09")], {"BG_OB_3": 8 * 15509}))
bg.append(bg_case("helgdag: Kristi himmelsfärdsdag", [inp("BG_OB_HELGDAG", "8", "2026-05-14")], {"BG_OB_HELGDAG": 8 * 22814}))
bg.append(bg_case("förskjuten tid", [inp("BG_FORSKJUTEN", "12", "2026-05-11")], {"BG_FORSKJUTEN": 12 * 3707}))
bg.append(bg_case("ung: 18 år med timlön under minimilönen", [inp("BG_OB_1", "4", "2026-05-05")], {"BG_OB_1": 4 * 5089}, warnings=["under minimilönen"],
                  employment=emp(YOUNG, salary_form="HOURLY", hourly_rate_ore=16_000, group="BAGERI")))
pay = hu(D(40_000_000) * 1320 / 10000 / 25 * 2)
bg.append(bg_case("semester: 13,2 %", [], {"SEMESTERLON": pay}, absences=week(18, 2), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": 40_000_000}))
bg.append(bg_case("semester: lägsta semesterlön per dag", [], {"SEMESTERLON": 2 * 178_900}, absences=week(18, 2), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": 5_000_000}))
bg.append(bg_case("deltid 50 %: lägsta semesterlön i proportion", [], {"SEMESTERLON": 2 * 89_450}, absences=week(18, 2), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": 2_000_000},
                  employment=emp(ADULT, salary_form="HOURLY", hourly_rate_ore=H, employment_rate_bp=5000, weekly_hours="20", group="BAGERI")))
fixtures["livsmedelsforetagen-livs-bageri"] = bg

# ---------------------------------------------------------------- Frisör (synthetic agreement, values invented for the test)
FR = "synthetic"
fr = []
S = 2_000_000
for name, turnover, bp, guarantee in [("provision över garantin", "80000", 3500, "SALARY"), ("provision under garantin: bara garantilönen", "40000", 3500, "SALARY"), ("provision utan garanti", "40000", 3500, "NONE")]:
    commission = hu(D(turnover) * 100 * bp / 10000)
    paid = max(0, commission - (S if guarantee == "SALARY" else 0))
    file = "synthetic-frisor-none.json" if guarantee == "NONE" else "synthetic-frisor.json"
    fr.append(case(name, "synthetic:" + file, emp(ADULT, monthly_salary_ore=S, group="SYNT"), [inp("SY_PROV", turnover, "2026-05-31")],
                   {"lines": {"SY_PROV": paid}, "gross_ore": S + paid, "employer": arbetare_costs(S + paid, 35), "warnings": []}))
commission = hu(D("60000") * 100 * 3500 / 10000)
fr.append(case("timlön som garanti", "synthetic:synthetic-frisor.json", emp(ADULT, salary_form="HOURLY", hourly_rate_ore=15_000, group="SYNT"), [{"code": "TIMLON", "quantity": "100", "date": "2026-05-04"}, inp("SY_PROV", "60000", "2026-05-31")],
               {"lines": {"SY_PROV": commission - 1_500_000}, "gross_ore": commission, "employer": arbetare_costs(commission, 35), "warnings": []}))
commission = hu(D("90000") * 100 * 4000 / 10000)
fr.append(case("lokalt avtal: högre provisionssats", "synthetic:synthetic-frisor.json", emp(ADULT, monthly_salary_ore=S, group="SYNT"), [inp("SY_PROV", "90000", "2026-05-31")],
               {"lines": {"SY_PROV": commission - S}, "gross_ore": commission, "employer": arbetare_costs(commission, 35), "warnings": []}))
fr[-1]["local_overrides"] = {"codes.SY_PROV.rate": {"value": {"type": "PERCENT_OF_TURNOVER", "bp": 4000}, "source": "LOKALT#s. 1", "verified_at": "2026-05-01T00:00:00+02:00"}}
pay = hu(D(30_000_000) * 1300 / 10000 / 25 * 5)
deduction = hu(D(40) * (D(S) * 12 / 52 / 40))
fr.append(case("semester: 13 % även på provision (syntetiskt)", "synthetic:synthetic-frisor.json", emp(ADULT, monthly_salary_ore=S, group="SYNT"), [],
               {"lines": {"SEMESTERLON": pay, "SEMESTERAVDRAG": -deduction}, "gross_ore": S - deduction + pay, "employer": arbetare_costs(S - deduction + pay, 35), "warnings": []},
               absences=week(18), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": 30_000_000}))
fixtures["frisor-syntetiskt"] = fr

os.makedirs(OUT, exist_ok=True)
for key, cases in fixtures.items():
    with open(os.path.join(OUT, f"{key}.json"), "w") as f:
        json.dump({"agreement": key, "note": "Expected amounts worked out by hand from the agreement data and the 2026 premium rules (docs/avtal/datamodell.md §6.8). Synthetic agreements carry invented values.", "cases": cases}, f, ensure_ascii=False, indent=2)
        f.write("\n")
    print(key, len(cases))
