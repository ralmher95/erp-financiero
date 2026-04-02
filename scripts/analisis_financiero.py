#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ARCHIVO: scripts/analisis_financiero.py
FUNCIONES: Analizar el estado financiero de un cliente.
"""
import os

def calcular_solvencia(activo, pasivo):
    if pasivo == 0: return "Excelente (Sin deuda)"
    ratio = activo / pasivo
    return f"{ratio:.2f}"

# Simulamos la lectura de datos de tu base de datos MySQL
activo_total = 50000.00
pasivo_total = 20000.00

solvencia = calcular_solvencia(activo_total, pasivo_total)

print("--- REPORTE AUTOMÁTICO DE SOLVENCIA ---")
print(f"Estado: {solvencia}")
print("---------------------------------------")       