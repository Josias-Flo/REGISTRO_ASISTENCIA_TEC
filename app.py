#!/usr/bin/env python3
from flask import Flask, Response, request, jsonify, stream_with_context
from flask_cors import CORS
import requests
import json
import time
from datetime import datetime
from threading import Thread, Lock
import logging
from queue import Queue, Empty

APPS_SCRIPT_URL = 'https://script.google.com/macros/s/AKfycbxtXZmzA8QnG1xO4kjEzx9sats4uSQRDrXWksD-p90Kc4stx4DhL1uML6Fg6AHEjzE1lg/exec'
INTERVALO_SEG = 8

app = Flask(__name__)
CORS(app)
logging.basicConfig(level=logging.INFO, format='[%(asctime)s] %(message)s')

cola_sse        = Queue()
cola_sse_ultimo = Queue()
registros       = []
registros_lock  = Lock()

# Variables globales para materias y materia activa
materias        = []   # Lista completa de materias con total_clases
materia_activa  = {}   # Materia activa con total_clases ya cruzado
materias_lock   = Lock()

# ── Helpers ──────────────────────────────────────────────────

def limpiar(valor, defecto='--'):
    if valor is None:
        return defecto
    s = str(valor).strip()
    return s if s else defecto

def procesar_fecha_hora(fecha_raw, hora_raw):
    """Parsea cualquier formato que mande Google Sheets y devuelve dd/MM/yyyy y HH:MM:SS."""

    # Ya viene formateado correctamente (dd/MM/yyyy)
    if fecha_raw and '/' in str(fecha_raw):
        return limpiar(fecha_raw), limpiar(hora_raw)

    # Google Sheets serializa fechas como: "Fri May 08 2026 22:00:08 GMT-0600 (...)"
    # Detectamos ese patrón y lo parseamos manualmente
    fecha_str = str(fecha_raw).strip() if fecha_raw else ''
    hora_str  = str(hora_raw).strip()  if hora_raw  else ''

    meses = {
        'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04',
        'May': '05', 'Jun': '06', 'Jul': '07', 'Aug': '08',
        'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12'
    }

    def parsear_date_string(s):
        """Intenta extraer fecha y hora de un string tipo Date de JS/Sheets."""
        try:
            # Formato: "Fri May 08 2026 22:00:08 GMT-0600 (...)"
            partes = s.split()
            if len(partes) >= 5:
                mes  = meses.get(partes[1], '00')
                dia  = partes[2].zfill(2)
                anio = partes[3]
                hora = partes[4]  # HH:MM:SS
                return f"{dia}/{mes}/{anio}", hora
        except Exception:
            pass
        return None, None

    # Intentar parsear el string de fecha
    fecha_f, hora_f = parsear_date_string(fecha_str)

    if fecha_f:
        # Si la hora vino separada en hora_raw, también parsearla
        if hora_str and hora_str != fecha_str:
            _, hora_alt = parsear_date_string(hora_str)
            if hora_alt:
                hora_f = hora_alt
        return fecha_f, hora_f if hora_f else limpiar(hora_raw)

    # Fallback: intentar ISO
    try:
        if fecha_raw:
            fecha_dt = datetime.fromisoformat(str(fecha_raw).replace('Z', '+00:00'))
            fecha_f  = fecha_dt.strftime('%d/%m/%Y')
        else:
            fecha_f = '--'
        if hora_raw:
            hora_dt = datetime.fromisoformat(str(hora_raw).replace('Z', '+00:00'))
            hora_f  = hora_dt.strftime('%H:%M:%S')
        else:
            hora_f = '--'
        return fecha_f, hora_f
    except Exception:
        return limpiar(fecha_raw), limpiar(hora_raw)

def clave_orden(r):
    f = r.get('fecha', '--')
    h = r.get('hora',  '--')
    if f != '--' and len(f) == 10:
        return f"{f[6:10]}/{f[3:5]}/{f[0:2]} {h}"
    return '0000/00/00 00:00:00'

# ── Fetch desde Apps Script ───────────────────────────────────

def fetch_datos_apps_script():
    """Trae los registros de asistencia (?tipo=asistencia por defecto)."""
    try:
        response = requests.get(
            APPS_SCRIPT_URL,
            headers={'Accept': 'application/json'},
            timeout=15
        )
        response.raise_for_status()
        data = response.json()

        if not isinstance(data, list):
            return []

        procesados = []
        for item in data:
            usuario = limpiar(
                item.get('usuario') or item.get('UID') or item.get('id'), ''
            )
            if not usuario:
                continue

            fecha_raw = item.get('fecha') or item.get('Fecha') or ''
            hora_raw  = item.get('hora')  or item.get('Hora')  or ''
            fecha, hora = procesar_fecha_hora(fecha_raw, hora_raw)

            procesados.append({
                'usuario': usuario,
                'fecha'  : fecha,
                'hora'   : hora,
                'nombre' : limpiar(item.get('nombre')  or item.get('Nombre')),
                'materia': limpiar(item.get('materia') or item.get('Materia')),
                'carrera': limpiar(item.get('carrera') or item.get('Carrera')),
                'grupo'  : limpiar(item.get('grupo')   or item.get('Grupo')),
                'maestro': limpiar(item.get('maestro') or item.get('Maestro')),
            })

        procesados.sort(key=clave_orden, reverse=True)
        return procesados

    except Exception as e:
        logging.error(f"Error fetching asistencia: {e}")
        return []


def fetch_materias():
    """Trae la lista de materias con total_clases (?tipo=materias)."""
    try:
        response = requests.get(
            APPS_SCRIPT_URL,
            params={'tipo': 'materias'},
            headers={'Accept': 'application/json'},
            timeout=15
        )
        response.raise_for_status()
        data = response.json()

        if not isinstance(data, list):
            return []

        resultado = []
        for m in data:
            resultado.append({
                'id'          : limpiar(m.get('id')),
                'nombre'      : limpiar(m.get('nombre')),
                'carrera'     : limpiar(m.get('carrera')),
                'grupo'       : limpiar(m.get('grupo')),
                'total_clases': int(m.get('total_clases') or 0),
                'id_maestro'  : limpiar(m.get('id_maestro')),
            })
        return resultado

    except Exception as e:
        logging.error(f"Error fetching materias: {e}")
        return []


def fetch_materia_activa():
    """Trae la materia activa (?tipo=activa) y la cruza con materias para obtener total_clases."""
    try:
        response = requests.get(
            APPS_SCRIPT_URL,
            params={'tipo': 'activa'},
            headers={'Accept': 'application/json'},
            timeout=15
        )
        response.raise_for_status()
        data = response.json()

        # Si viene vacío no hay materia activa
        if not data or not data.get('id_materia'):
            return {}

        id_materia = limpiar(data.get('id_materia'))

        # Cruzar con la lista de materias para obtener total_clases
        total_clases = 0
        nombre_materia = '--'
        with materias_lock:
            for m in materias:
                if m['id'] == id_materia:
                    total_clases   = m['total_clases']
                    nombre_materia = m['nombre']
                    break

        return {
            'id_materia'   : id_materia,
            'nombre'       : nombre_materia,
            'maestro'      : limpiar(data.get('maestro')),
            'id_maestro'   : limpiar(data.get('id_maestro')),
            'hora_inicio'  : limpiar(data.get('hora_inicio')),
            'total_clases' : total_clases,
        }

    except Exception as e:
        logging.error(f"Error fetching materia activa: {e}")
        return {}

# ── Ciclo de actualización ────────────────────────────────────

def ciclo_actualizacion():
    global registros, materias, materia_activa
    ultima_clave = None
    ciclo      = 0  # Contador para actualizar materias con menos frecuencia

    while True:
        try:
            # Actualizar materias cada 5 ciclos (~40 seg) — cambian poco
            if ciclo % 5 == 0:
                nuevas_materias = fetch_materias()
                if nuevas_materias:
                    with materias_lock:
                        materias = nuevas_materias
                    logging.info(f"{len(materias)} materias cargadas")

            # Actualizar materia activa cada ciclo — puede cambiar seguido
            nueva_activa = fetch_materia_activa()
            with materias_lock:
                materia_activa = nueva_activa

            if materia_activa:
                logging.info(f"Materia activa: {materia_activa.get('nombre')} | total_clases={materia_activa.get('total_clases')}")
            else:
                logging.info("Sin materia activa")

            # Actualizar registros de asistencia
            nuevos = fetch_datos_apps_script()
            with registros_lock:
                registros = nuevos

            logging.info(f"{len(registros)} registros cargados")

            if nuevos:
                cola_sse.put(json.dumps(nuevos, ensure_ascii=False))

                r = nuevos[0]
                nueva_clave = r.get('usuario', '') + '|' + r.get('fecha', '') + '|' + r.get('hora', '')
                if nueva_clave != ultima_clave:
                    ultima_clave = nueva_clave
                    cola_sse_ultimo.put(json.dumps(r, ensure_ascii=False))

            ciclo += 1

        except Exception as e:
            logging.error(f"Error en ciclo: {e}")

        time.sleep(INTERVALO_SEG)

Thread(target=ciclo_actualizacion, daemon=True).start()

# ── Endpoints ─────────────────────────────────────────────────

@app.route('/api/asistencia')
def api_asistencia():
    """Devuelve todos los registros."""
    with registros_lock:
        return jsonify(registros)

@app.route('/api/ultimo')
def api_ultimo():
    """Devuelve solo el último registro."""
    with registros_lock:
        if registros:
            return jsonify(registros[0])
        return jsonify({})

@app.route('/api/alumno/<uid>')
def api_alumno(uid):
    """Devuelve todos los registros de un UID específico."""
    uid = uid.upper().strip()
    with registros_lock:
        filtrados = [r for r in registros if r.get('usuario', '').upper() == uid]
    return jsonify(filtrados)

@app.route('/api/materia-activa')
def api_materia_activa():
    """
    Devuelve la materia activa con total_clases ya cruzado.
    Ejemplo de respuesta:
    {
        "id_materia"  : "MAT_1234567890",
        "nombre"      : "Programación Web",
        "maestro"     : "Juan Pérez",
        "id_maestro"  : "MAE_1234567890",
        "hora_inicio" : "08:00:00",
        "total_clases": 48
    }
    """
    with materias_lock:
        return jsonify(materia_activa)

@app.route('/api/materias')
def api_materias():
    """Devuelve la lista completa de materias (útil para debug)."""
    with materias_lock:
        return jsonify(materias)

@app.route('/api/stream')
def api_stream():
    """SSE para index.php — lista completa actualizada."""
    def event_stream():
        with registros_lock:
            if registros:
                yield f"data: {json.dumps(registros, ensure_ascii=False)}\n\n"
        while True:
            try:
                datos = cola_sse.get(timeout=30)
                yield f"data: {datos}\n\n"
                cola_sse.task_done()
            except Empty:
                yield ": keepalive\n\n"

    return Response(
        stream_with_context(event_stream()),
        mimetype='text/event-stream',
        headers={
            'Cache-Control'              : 'no-cache',
            'Connection'                 : 'keep-alive',
            'X-Accel-Buffering'          : 'no',
            'Access-Control-Allow-Origin': '*',
        }
    )

@app.route('/api/stream/ultimo')
def api_stream_ultimo():
    """SSE para bienvenida.php — solo el último registro cuando cambia."""
    def event_stream():
        with registros_lock:
            if registros:
                yield f"data: {json.dumps(registros[0], ensure_ascii=False)}\n\n"
        while True:
            try:
                datos = cola_sse_ultimo.get(timeout=30)
                yield f"data: {datos}\n\n"
                cola_sse_ultimo.task_done()
            except Empty:
                yield ": keepalive\n\n"

    return Response(
        stream_with_context(event_stream()),
        mimetype='text/event-stream',
        headers={
            'Cache-Control'              : 'no-cache',
            'Connection'                 : 'keep-alive',
            'X-Accel-Buffering'          : 'no',
            'Access-Control-Allow-Origin': '*',
        }
    )

@app.route('/health')
def health():
    with registros_lock:
        total = len(registros)
    with materias_lock:
        activa = materia_activa.get('nombre', 'Ninguna')
        total_clases = materia_activa.get('total_clases', 0)
    return jsonify({
        'status'        : 'ok',
        'registros'     : total,
        'materia_activa': activa,
        'total_clases'  : total_clases,
        'timestamp'     : datetime.now().isoformat()
    })

# ── Inicio ────────────────────────────────────────────────────

if __name__ == '__main__':
    print("\n" + "="*60)
    print("  Backend Python corriendo en:")
    print("    http://localhost:5000/api/asistencia")
    print("    http://localhost:5000/api/ultimo")
    print("    http://localhost:5000/api/materia-activa")
    print("    http://localhost:5000/api/materias")
    print("    http://localhost:5000/api/stream")
    print("    http://localhost:5000/api/stream/ultimo")
    print("="*60 + "\n")
    app.run(host='0.0.0.0', port=5000, threaded=True, debug=False)