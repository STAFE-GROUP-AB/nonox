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
# Overtime and mertid supplements for hourly pay and vacation pay on them (fix-overtid, L5/A1;
# enligt AI-utredning 2026-11-02, juristgranskning återstår): the hourly pay already pays the hour, so the
# supplement-only code adds 35 % from 2026-04-01; both are vacation-pay basis (GR § 17 p. 1, semesterlagen 16 b §),
# so semesterersättning with the salary is 12,72 % of hourly pay plus supplement.
HR = 16_000
r = percent_rate(HR, 3500)
paid = 100 * HR + 3 * r
pay_out = hu(D(paid) * 1272 / 10000)
g = paid + pay_out
gr.append(case("timavlönad: övertidstillägg a) utan timlönen igen, semesterersättning även på tillägget", GR, emp(ADULT, salary_form="HOURLY", hourly_rate_ore=HR, group="2"),
               [{"code": "TIMLON", "quantity": "100", "date": "2026-05-04"}, inp("GR_OT_A_TL", "3", "2026-05-12")],
               {"lines": {"TIMLON": 100 * HR, "GR_OT_A_TL": 3 * r, "SEMESTERERSATTNING": pay_out}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []},
               vacation={"rule": "PROCENTREGELN", "paid_days_earned": 0, "basis_ore": 0, "payout_with_salary": True}))
rm = percent_rate(HR, 3500)
g = 100 * HR + 2 * rm
gr.append(case("timavlönad: mertidstillägg a) 35 % från 2026-04-01", GR, emp(ADULT, salary_form="HOURLY", hourly_rate_ore=HR, employment_rate_bp=5000, weekly_hours="20", group="2"),
               [{"code": "TIMLON", "quantity": "100", "date": "2026-05-04"}, inp("GR_MT_A_TL", "2", "2026-05-13")],
               {"lines": {"TIMLON": 100 * HR, "GR_MT_A_TL": 2 * rm}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}))
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

# Detaljhandeln: overtime is in the vacation-pay basis (D1 § 14.6 A excludes only semesterlön, sjuklön and
# permitteringslön), for hourly pay through the supplement-only code (fix-overtid).
HR = 18_000
r = percent_rate(HR, 3500)
paid = 120 * HR + 2 * r
pay_out = hu(D(paid) * 1300 / 10000)
g = paid + pay_out
dh.append(case("timavlönad: övertidstillägg 35 % och semesterersättning 13 % även på tillägget", DH, emp(ADULT, salary_form="HOURLY", hourly_rate_ore=HR, group="ALLA"),
               [{"code": "TIMLON", "quantity": "120", "date": "2026-05-04"}, inp("DH_OT_A_TL", "2", "2026-05-12")],
               {"lines": {"TIMLON": 120 * HR, "DH_OT_A_TL": 2 * r, "SEMESTERERSATTNING": pay_out}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []},
               vacation={"rule": "PROCENTREGELN", "paid_days_earned": 0, "basis_ore": 0, "payout_with_salary": True}))
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
# Overtime pay includes semesterlön (HT § 9.4.1): the overtime is not in the basis of semesterersättning (law's 12 %).
r = hu(D(S) / 94)
pay_out = hu(D(S) * 1200 / 10000)
g = S + 2 * r + pay_out
ht.append(case("övertid /94 ger ingen semesterersättning: semesterlönen ingår i övertidsersättningen", HT, emp(ADULT, monthly_salary_ore=S, group="UNIONEN"), [inp("HT_OT_94", "2", "2026-05-12")],
               {"lines": {"HT_OT_94": 2 * r, "SEMESTERERSATTNING": pay_out}, "gross_ore": g, "employer": tjm_costs(g, 35), "warnings": []},
               vacation={"rule": "SAMMALONEREGELN", "paid_days_earned": 0, "basis_ore": 0, "payout_with_salary": True}))
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
# Overtime pay includes semesterlön (VU § 16): not in the basis of semesterersättning (law's 12 %).
r = hu(D(S) / 94)
pay_out = hu(D(S) * 1200 / 10000)
g = S + 3 * r + pay_out
vu.append(case("övertid /94 ger ingen semesterersättning: semesterlönen ingår i övertidsersättningen", VU, emp(ADULT, monthly_salary_ore=S, group="ALLA"), [inp("VU_OT_94", "3", "2026-05-13")],
               {"lines": {"VU_OT_94": 3 * r, "SEMESTERERSATTNING": pay_out}, "gross_ore": g, "employer": tjm_costs(g, 35), "warnings": []},
               vacation={"rule": "SAMMALONEREGELN", "paid_days_earned": 0, "basis_ore": 0, "payout_with_salary": True}))
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

# ---------------------------------------------------------------- Träindustriavtalet (TMF–GS, L1)
# The 2025-04-01 file: amounts from Adda's procurement annex (ADDA25), which cannot be verified, and the
# mechanics of the 2023–2025 agreement text (TI23). The golden payslips check that the engine reads the file;
# a real run stays blocked until a human can verify the version. The file has no 2026-04-01 step, so the
# cases lie before April 2026.
TMF = "tmf-gs-traindustri/MEMBER-2025-04-01.json"
TH = 18_000                    # 180 kr per hour
TS = 3_150_000                 # 31 500 kr a month = 180 kr × 175
tmf = []


def tmf_hourly(name, inputs, lines, period="2026-03", payment="2026-03-25", hours=160, employment=None, warnings=None, **kw):
    e = employment or emp(ADULT, salary_form="HOURLY", hourly_rate_ore=TH, group="YRKES")
    base = hours * e["hourly_rate_ore"]
    g = base + sum(lines.values())
    return case(name, TMF, e, [{"code": "TIMLON", "quantity": str(hours), "date": f"{period}-02"}] + inputs,
                {"lines": lines, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": warnings or []}, period=period, payment=payment, **kw)


def tmf_monthly(name, inputs, lines, employment=None, period="2026-03", payment="2026-03-25"):
    e = employment or emp(ADULT, monthly_salary_ore=TS, group="YRKES")
    salary = e["monthly_salary_ore"] * e["employment_rate_bp"] // 10000
    g = salary + sum(lines.values())
    return case(name, TMF, e, inputs, {"lines": {"MANADSLON": salary, **lines}, "gross_ore": g, "employer": arbetare_costs(g, 35), "warnings": []}, period=period, payment=payment)


def march_vacation(n):
    return [{"type": "VACATION", "date": f"2026-03-{16 + i:02d}", "hours": "8"} for i in range(n)]


tmf.append(tmf_hourly("timavlönad yrkesarbetare: timlön", [], {}))
tmf.append(tmf_hourly("OB a) måndag–torsdag kl. 17–24", [inp("TMF_OB_A", "10", "2026-03-03")], {"TMF_OB_A": 10 * 3880}))
tmf.append(tmf_hourly("OB b) natt tisdag–fredag kl. 00–05", [inp("TMF_OB_B", "5", "2026-03-04")], {"TMF_OB_B": 5 * 4625}))
tmf.append(tmf_hourly("OB c) lördag", [inp("TMF_OB_C", "8", "2026-03-07")], {"TMF_OB_C": 8 * 8860}))
tmf.append(tmf_hourly("OB d) trettondagen", [inp("TMF_OB_D", "6", "2026-01-06")], {"TMF_OB_D": 6 * 10670}, period="2026-01", payment="2026-01-23"))
tmf.append(tmf_hourly("OB e) julafton", [inp("TMF_OB_E", "8", "2025-12-24")], {"TMF_OB_E": 8 * 22435}, period="2025-12", payment="2026-01-23"))
tmf.append(tmf_hourly("helgdagslön för timavlönad: trettondagen på en arbetsdag", [inp("TMF_HELGDAG", "8", "2026-01-06")], {"TMF_HELGDAG": 8 * TH}, period="2026-01", payment="2026-01-23"))
tmf.append(tmf_hourly("timavlönad: övertidstillägg 58,50 kr utan timlönen igen", [inp("TMF_OT_TL", "4", "2026-03-10")], {"TMF_OT_TL": 4 * 5850}))
tmf.append(tmf_hourly("timavlönad deltid 50 %: mertidstillägg", [inp("TMF_MT_TL", "3", "2026-03-11")], {"TMF_MT_TL": 3 * 5850}, hours=80,
                      employment=emp(ADULT, salary_form="HOURLY", hourly_rate_ore=TH, employment_rate_bp=5000, weekly_hours="20", group="YRKES")))
tmf.append(tmf_hourly("skiftformstillägg kontinuerligt tvåskift 8,1 %", [inp("TMF_SKIFT_2K", "120", "2026-03-02")], {"TMF_SKIFT_2K": 120 * percent_rate(TH, 810)}))
tmf.append(tmf_hourly("arbete utom verkstaden 10 %", [inp("TMF_UTOM", "20", "2026-03-12")], {"TMF_UTOM": 20 * percent_rate(TH, 1000)}))
tmf.append(tmf_hourly("yrkesarbetare med fem års branschvana under lägsta timlönen", [], {}, warnings=["under minimilönen"],
                      employment=emp(ADULT, salary_form="HOURLY", hourly_rate_ore=17_000, group="YRKES", experience_from="2020-01-01")))
tmf.append(tmf_monthly("månadsavlönad: övertid med 1/175 av månadslönen och tillägget", [inp("TMF_OT", "3", "2026-03-10")], {"TMF_OT": 3 * (TS // 175 + 5850)}))
pt = hu(D(TS) * 7500 / 10000 / (D(30) * D("4.375")))
tmf.append(tmf_monthly("månadsavlönad deltid 75 %: mertid med deltidens timlön och tillägget", [inp("TMF_MT", "2", "2026-03-11")], {"TMF_MT": 2 * (pt + 5850)},
                       employment=emp(ADULT, monthly_salary_ore=TS, employment_rate_bp=7500, weekly_hours="30", group="YRKES")))
pay = hu(D(40_000_000) * 1296 / 10000 / 25 * 2)
tmf.append(tmf_hourly("semester: 12,96 % (12 % uppräknat med 1,08)", [], {"SEMESTERLON": pay}, absences=march_vacation(2), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": 40_000_000}))
tmf.append(tmf_hourly("semester: lägsta semesterlön per dag", [], {"SEMESTERLON": 2 * 169_000}, absences=march_vacation(2), vacation={"rule": "PROCENTREGELN", "paid_days_earned": 25, "basis_ore": 5_000_000}))
fixtures["tmf-gs-traindustri"] = tmf

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

# ---------------------------------------------------------------- Sick pay (L1, L2, L4; enligt AI-utredning 2026-11-02, juristgranskning återstår)
# Sjuklönelagen 6–7 §§, prop. 2017/18:96 s. 68–69 and the agreements' sick-pay sections. The engine keeps 10
# decimals (Units::SCALE) and rounds half up to whole öre per line; Decimal below does the same.
import datetime

SCALE = D("0.0000000001")


def sc(x):
    return D(x).quantize(SCALE, rounding=ROUND_HALF_UP)


def sick(date, hours="8", extent=None):
    a = {"type": "SICK", "date": date, "hours": hours}
    if extent is not None:
        a["extent_bp"] = extent
    return a


def date_range(first, last):
    d = datetime.date.fromisoformat(first)
    end = datetime.date.fromisoformat(last)
    out = []
    while d <= end:
        out.append(d.isoformat())
        d += datetime.timedelta(days=1)
    return out


def days(first, last, hours_by_weekday=("8", "8", "8", "8", "8", "0", "0"), holidays=("2026-05-01", "2026-05-14"), extent=None):
    return [sick(d, "0" if d in holidays else hours_by_weekday[datetime.date.fromisoformat(d).weekday()], extent) for d in date_range(first, last)]


def kr(ore):
    return f"{ore // 100},{ore % 100:02d}"


def sick_case(name, file, employment, absences, lines, gross, warnings, history=None, facts=None, periods=None):
    c = case(name, file, employment, [], {"lines": lines, "gross_ore": gross, "employer": {}, "warnings": warnings}, absences=absences)
    if history is not None:
        c["sick_history"] = history
    if facts is not None:
        c["sick_facts"] = facts
    if periods is not None:
        c["expected"]["sick_periods"] = periods
    return c


# Visita–Unionen: hour value monthly × 12 / (52 × weekly hours) (VU § 23.3), the same as the law's default; karens 20 % of the
# weekly hours in time at that value: 0,2 × 40 h × hour value × 80 %.
S = 3_500_000
HV = sc(D(S) * 12 / (52 * 40))                   # 20 192,3076923077 öre
KARENS = hu(sc(D(8) * HV) * 8000 / 10000)       # 1 292,31 kr
E = emp(ADULT, monthly_salary_ore=S, group="ALLA")
vu_s = []

# L2 (1): two sjuklöneperioder in one month each get their own karensavdrag, capped at that period's own sick pay.
pay_a = hu(D(4) * HV * 8000 / 10000)            # 4 h on 6 May
pay_b = hu(D(16) * HV * 8000 / 10000)           # 18–19 May
k_a, k_b = min(KARENS, pay_a), min(KARENS, pay_b)
lost = hu(D(20) * HV)
pay = hu(D(20) * HV * 8000 / 10000)
vu_s.append(sick_case("sjuk: två sjukperioder i en månad, var sin karens, högst periodens sjuklön", VU, E,
                      [sick("2026-05-06", "4"), sick("2026-05-18"), sick("2026-05-19")],
                      {"SJUKAVDRAG": -lost, "SJUKLON": pay, "KARENSAVDRAG": -(k_a + k_b)}, S - lost + pay - k_a - k_b,
                      [f"Resten, {kr(KARENS - k_a)} kr, dras inte"],
                      periods=[{"period_start": "2026-05-06", "karens_full_ore": KARENS, "karens_deducted_ore": k_a},
                               {"period_start": "2026-05-18", "karens_full_ore": KARENS, "karens_deducted_ore": k_b}]))

# L2 (2): the rest of April's karensavdrag (sick 29 April, 2 h) is taken in May after reinfection within five days.
deducted = hu(D(2) * HV * 8000 / 10000)
lost = hu(D(8) * HV)
pay = hu(D(8) * HV * 8000 / 10000)
k = min(KARENS - deducted, pay)
vu_s.append(sick_case("sjuk: resten av karensavdraget efter återinsjuknande i nästa månad", VU, E, [sick("2026-05-04")],
                      {"SJUKAVDRAG": -lost, "SJUKLON": pay, "KARENSAVDRAG": -k}, S - lost + pay - k, [],
                      history={"spells": [{"from": "2026-04-29", "to": "2026-04-29"}], "periods": {"2026-04-29": {"full": KARENS, "deducted": deducted}}},
                      periods=[{"period_start": "2026-04-29", "karens_full_ore": KARENS, "karens_deducted_ore": k}]))

# L2 (2): the same sick period over the month end (1 h on 30 April, then 1–4 May): the rest follows into May.
deducted = hu(D(1) * HV * 8000 / 10000)
k = min(KARENS - deducted, pay)
vu_s.append(sick_case("sjuk: karensrest förs över månadsskiftet i samma sjukperiod", VU, E, days("2026-05-01", "2026-05-04"),
                      {"SJUKAVDRAG": -lost, "SJUKLON": pay, "KARENSAVDRAG": -k}, S - lost + pay - k, [],
                      history={"spells": [{"from": "2026-04-30", "to": "2026-04-30"}], "periods": {"2026-04-30": {"full": KARENS, "deducted": deducted}}},
                      periods=[{"period_start": "2026-04-30", "day_count": 5, "karens_deducted_ore": k}]))

# L2 (5): day numbering follows the whole chain (10 days, back 1, 3 days, back 3, sick again = day 14), not the last record.
# Day 14 is the last day with sick pay; days 15–16 take the agreement's deduction per calendar day (VU § 23.3, below).
lost = hu(D(8) * HV) + 2 * hu(sc(D(S) * 12 * 9000 / (10000 * 365)))
pay = hu(D(8) * HV * 8000 / 10000)
vu_s.append(sick_case("sjuk: dagnumren följer hela kedjan av återinsjuknanden", VU, E, [sick("2026-05-04"), sick("2026-05-05"), sick("2026-05-06")],
                      {"SJUKAVDRAG": -lost, "SJUKLON": pay}, S - lost + pay, ["kost- och bostadsförmån", "Försäkringskassan"],
                      history={"spells": [{"from": "2026-04-17", "to": "2026-04-26"}, {"from": "2026-04-28", "to": "2026-04-30"}], "periods": {"2026-04-17": {"full": KARENS, "deducted": KARENS}}},
                      periods=[{"period_start": "2026-04-17", "day_count": 16}]))

# L2 (3)/(4): ten occasions counted back from the NEW period's first day (20 May), not from the run's start.
ten = ["2025-05-10", "2025-06-10", "2025-07-10", "2025-08-10", "2025-09-10", "2025-10-10", "2025-11-10", "2025-12-10", "2026-01-10", "2026-02-10"]
hist = {"spells": [{"from": d, "to": d} for d in ten], "periods": {d: {"full": KARENS, "deducted": KARENS} for d in ten}}
lost = hu(D(8) * HV)
pay = hu(D(8) * HV * 8000 / 10000)
vu_s.append(sick_case("sjuk: tolv månader räknas bakåt från sjukperiodens första dag", VU, E, [sick("2026-05-20")],
                      {"SJUKAVDRAG": -lost, "SJUKLON": pay, "KARENSAVDRAG": -min(KARENS, pay)}, S - lost + pay - min(KARENS, pay), [], history=hist))
vu_s.append(sick_case("sjuk: inget karensavdrag efter tio tillfällen", VU, E, [sick("2026-05-05")],
                      {"SJUKAVDRAG": -lost, "SJUKLON": pay}, S - lost + pay, ["redan haft karensavdrag 10 gånger"], history=hist,
                      periods=[{"period_start": "2026-05-05", "karens_full_ore": 0, "karens_deducted_ore": 0}]))
# A period in which no karensavdrag was actually made (no sick pay) is not an occasion.
nine = {**hist, "periods": {**hist["periods"], "2025-09-10": {"full": KARENS, "deducted": 0}}}
vu_s.append(sick_case("sjuk: tillfällen är gjorda karensavdrag, inte frånvaroposter", VU, E, [sick("2026-05-05")],
                      {"SJUKAVDRAG": -lost, "SJUKLON": pay, "KARENSAVDRAG": -min(KARENS, pay)}, S - lost + pay - min(KARENS, pay), [], history=nine))

# L4: from day 15 the agreement's deduction per calendar day: 90 % × monthly × 12 / 365 below 10 prisbasbelopp (VU § 23.3).
DAY = hu(sc(D(S) * 12 * 9000 / (10000 * 365)))   # 1 035,62 kr
# Ceiling of 105 days from day 15 in the twelve months back from the period's first day: 100 used, 5 left (4–8 May).
used = date_range("2025-09-01", "2025-12-09")
lost = hu(D(24) * HV)                           # 11–13 May back to the hourly deduction
vu_s.append(sick_case("sjuk dag 15+: taket 105 dagar på tolv månader", VU, E, days("2026-05-01", "2026-05-14"),
                      {"SJUKAVDRAG": -(lost + 5 * DAY)}, S - lost - 5 * DAY, ["kost- och bostadsförmån", "Taket på 105 dagar", "Försäkringskassan"],
                      history={"spells": [{"from": "2026-04-20", "to": "2026-04-30"}], "periods": {"2026-04-20": {"full": KARENS, "deducted": KARENS}}, "long_sick_dates": used}))

# L4: the group is decided on the sick period's first day (20 March, eleven months' employment: to day 45) …
E2 = emp({"birth_date": "1990-06-15", "start": "2025-04-21"}, monthly_salary_ore=S, group="ALLA")
hist2 = {"spells": [{"from": "2026-03-20", "to": "2026-04-30"}], "periods": {"2026-03-20": {"full": KARENS, "deducted": KARENS}}, "long_sick_dates": date_range("2026-04-03", "2026-04-30")}
lost = hu(D(40) * HV)                           # 4–8 May: days 46–50, after day 45
vu_s.append(sick_case("sjuk dag 15+: gruppen avgörs på sjukperiodens första dag", VU, E2, days("2026-05-01", "2026-05-10"),
                      {"SJUKAVDRAG": -(lost + 3 * DAY)}, S - lost - 3 * DAY, ["kost- och bostadsförmån", "Försäkringskassan"], history=hist2))
# … unless the employee came directly from an employment with sick pay to day 90.
vu_s.append(sick_case("sjuk dag 15+: direkt övergång från anställning med 90 dagar", VU, {**E2, "sick_pay_direct_transfer": True}, days("2026-05-01", "2026-05-10"),
                      {"SJUKAVDRAG": -10 * DAY}, S - 10 * DAY, ["kost- och bostadsförmån", "Försäkringskassan"], history=hist2))
# Part-time sick leave from day 15: the whole deduction per calendar day, and a note to check it.
hist3 = {"spells": [{"from": "2026-04-17", "to": "2026-04-30"}], "periods": {"2026-04-17": {"full": KARENS, "deducted": KARENS}}}
vu_s.append(sick_case("sjuk dag 15+: deltidssjuk får hela avdraget och en notering", VU, E, days("2026-05-01", "2026-05-03", hours_by_weekday=("4",) * 5 + ("0", "0"), extent=5000),
                      {"SJUKAVDRAG": -3 * DAY}, S - 3 * DAY, ["kost- och bostadsförmån", "Deltidssjukskrivning", "Försäkringskassan"], history=hist3))
fixtures["visita-unionen"] += vu_s

# Handelns tjänstemannaavtal: hour value monthly × 12,2 / (52 × weekly hours) (HT § 10.3.2), karens 20 % of the weekly hours.
S = 3_800_000
HV = sc(D(S) * D("12.2") / (52 * 40))
E = emp(ADULT, monthly_salary_ore=S, group="UNIONEN")
lost = hu(D(16) * HV)
pay = hu(D(16) * HV * 8000 / 10000)
k = min(hu(sc(D(8) * HV) * 8000 / 10000), pay)
fixtures["svenskhandel-unionen-tjansteman"].append(sick_case("sjuk: timvärde 12,2/52 och karens i tid", HT, E, [sick("2026-05-06"), sick("2026-05-07")],
                                                            {"SJUKAVDRAG": -lost, "SJUKLON": pay, "KARENSAVDRAG": -k}, S - lost + pay - k, []))
# Day 15+: "månadslön" adds the fixed supplements (on the employment) and the monthly average of variable pay; the ceiling
# per day is the fixed salary (salary + fixed supplements) × 12 / 365 (HT § 10.3.6).
hist = {"spells": [{"from": "2026-04-17", "to": "2026-04-30"}], "periods": {"2026-04-17": {"full": 0, "deducted": 0}}}
for name, variable in [("sjuk dag 15+: fasta tillägg och rörligt snitt i månadslönen", 300_000), ("sjuk dag 15+: taket per dag är den fasta lönen", 1_000_000)]:
    yearly = (S + 200_000 + variable) * 12
    below = min(yearly, 10 * PBB)
    above = max(0, yearly - 10 * PBB)
    per_day = sc(D(below * 9000 + above * 1000) / (10000 * 365))
    cap = sc(D((S + 200_000) * 12) / 365)
    day = hu(min(per_day, cap))
    fixtures["svenskhandel-unionen-tjansteman"].append(sick_case(name, HT, {**E, "fixed_supplements_ore": 200_000}, days("2026-05-01", "2026-05-03"),
                                                                {"SJUKAVDRAG": -3 * day}, S - 3 * day, ["kost- och bostadsförmån", "Försäkringskassan"], history=hist, facts={"variable_monthly_ore": variable}))

# Gröna Riks: hour value monthly / the working hours of the calendar month (GR § 20 p. 4.3.1): May 2026 by the weekly
# pattern, 1 and 14 May are public holidays: 19 days × 8 h = 152 h. Karens 20 % of the weekly hours.
S = 3_200_000
HV = sc(D(S) / 152)
lost = hu(D(16) * HV)
pay = hu(D(16) * HV * 8000 / 10000)
k = min(hu(sc(D(8) * HV) * 8000 / 10000), pay)
fixtures["visita-hrf-grona-riks"].append(sick_case("sjuk: timvärde månadslön delad med månadens arbetstimmar", GR, emp(ADULT, monthly_salary_ore=S, group="2", week_pattern=["8", "8", "8", "8", "8", "0", "0"]),
                                                   [sick("2026-05-06"), sick("2026-05-07")], {"SJUKAVDRAG": -lost, "SJUKLON": pay, "KARENSAVDRAG": -k}, S - lost + pay - k, []))

# Detaljhandeln: 4,6 % of the monthly salary per full sick day, 1/166 per hour of a part day, sick pay 80 % of the deduction
# (D1 § 13.1, § 15.4); karens 20 % of the weekly hours agreed in advance, valued per hour (1/166) with a note.
S = 3_000_000
part = sc(D(S) / 166)
lost_exact = D(S) * 460 / 10000 + 4 * part
lost = hu(lost_exact)
pay = hu(lost_exact * 8000 / 10000)
k = min(hu(sc(D(8) * part) * 8000 / 10000), pay)
fixtures["svenskhandel-handels-detaljhandel"].append(sick_case("sjuk: 4,6 % per hel dag och 1/166 per timme", DH, emp(ADULT, monthly_salary_ore=S, group="ALLA", week_pattern=["8", "8", "8", "8", "8", "0", "0"]),
                                                               [sick("2026-05-06"), sick("2026-05-07", "4")], {"SJUKAVDRAG": -lost, "SJUKLON": pay, "KARENSAVDRAG": -k}, S - lost + pay - k, ["1/166"]))

os.makedirs(OUT, exist_ok=True)
for key, cases in fixtures.items():
    with open(os.path.join(OUT, f"{key}.json"), "w") as f:
        json.dump({"agreement": key, "note": "Expected amounts worked out by hand from the agreement data and the 2026 premium rules (docs/avtal/datamodell.md §6.8). Synthetic agreements carry invented values.", "cases": cases}, f, ensure_ascii=False, indent=2)
        f.write("\n")
    print(key, len(cases))
