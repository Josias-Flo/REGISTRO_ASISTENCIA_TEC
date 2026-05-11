#!/usr/bin/env python3
from flask import Flask, Response, request, jsonify, stream_with_context, send_from_directory
import requests
import json
import time
from datetime import datetime
from threading import Thread, Lock
import logging
from queue import Queue, Empty

APPS_SCRIPT_URL = 'https://script.googleusercontent.com/macros/echo?user_content_key=AWDtjMUZVAhed3nmkZJIWY-_ODiWazs3_AiTlrC3zdZaZA0P6J9jkz1lYOpm1ImZk-6gfIJDnWjzq83FOWroLPdvCCdZiqfyn_83bWYO_Z872KN7BxQ2hO6M2iBi4HuYv4zsl1LBgyQDBpF9ITl7tX7Prj9-CtKshYWsOvleYt-4hweUccDBFzHGVf0IFbLgNWV9dlnzKZ6mXsO8JM-VaFiqoic8RoQtq-Rp4W43Wfo61a6pn4YJ5-TwhAGA12QixVZUm1T661kU7_njEHW6oog&lib=MtOcDzBj_QYDwfjyYHtViu5N6HcRh5VzV'
INTERVALO_MS = 8

app = Flask(__name__, static_folder='.')
logging.basicConfig(level=logging.INFO, format='[%(asctime)s] %(message)s')

# Cola para mensajes SSE
cola_sse = Queue()
registros = []
registros_lock = Lock()

def procesar_fecha_hora(fecha_iso, hora_iso):
    try:
        if fecha_iso:
            fecha_dt = datetime.fromisoformat(fecha_iso.replace('Z', '+00:00'))
            fecha_formateada = fecha_dt.strftime('%d/%m/%Y')
        else:
            fecha_formateada = '--'
        
        if hora_iso:
            hora_dt = datetime.fromisoformat(hora_iso.replace('Z', '+00:00'))
            hora_formateada = hora_dt.strftime('%H:%M:%S')
        else:
            hora_formateada = '--'
        
        return fecha_formateada, hora_formateada
    except Exception as e:
        return '--', '--'

def fetch_datos_apps_script():
    try:
        response = requests.get(APPS_SCRIPT_URL, headers={'Accept': 'application/json'}, timeout=10)
        response.raise_for_status()
        data = response.json()
        
        if not isinstance(data, list):
            return []
        
        registros_procesados = []
        for item in data:
            usuario = item.get('usuario') or item.get('UID') or item.get('id') or ''
            if not usuario:
                continue
                
            fecha_raw = item.get('fecha') or item.get('Fecha') or ''
            hora_raw = item.get('hora') or item.get('Hora') or ''
            
            fecha, hora = procesar_fecha_hora(fecha_raw, hora_raw)
            
            registros_procesados.append({
                'usuario': str(usuario).strip(),
                'fecha': fecha,
                'hora': hora
            })
        
        registros_procesados.sort(key=lambda x: f"{x['fecha']} {x['hora']}", reverse=True)
        return registros_procesados
        
    except Exception as e:
        logging.error(f"Error fetching Apps Script: {e}")
        return []

def ciclo_actualizacion():
    global registros
    
    while True:
        try:
            nuevos_datos = fetch_datos_apps_script()
            
            with registros_lock:
                registros = nuevos_datos
            
            logging.info(f"✓ {len(registros)} registros cargados")
            
            if nuevos_datos:
                cola_sse.put(json.dumps(nuevos_datos, ensure_ascii=False))
                
        except Exception as e:
            logging.error(f"Error en ciclo: {e}")
        
        time.sleep(INTERVALO_MS)

Thread(target=ciclo_actualizacion, daemon=True).start()

@app.route('/')
def index():
    return send_from_directory('.', 'index.html')

@app.route('/api/asistencia')
def api_asistencia():
    with registros_lock:
        return jsonify(registros)

@app.route('/api/stream')
def api_stream():
    def event_stream():
        # Enviar datos iniciales si existen
        with registros_lock:
            if registros:
                yield f"data: {json.dumps(registros, ensure_ascii=False)}\n\n"
        
        # Enviar actualizaciones
        while True:
            try:
                datos = cola_sse.get(timeout=30)
                # AQUÍ ESTÁ LA CORRECCIÓN: Agregar 'data: ' antes del JSON
                yield f"data: {datos}\n\n"
                cola_sse.task_done()
            except Empty:
                yield ": keepalive\n\n"
    
    return Response(
        stream_with_context(event_stream()),
        mimetype='text/event-stream',
        headers={
            'Cache-Control': 'no-cache',
            'Connection': 'keep-alive',
            'X-Accel-Buffering': 'no'
        }
    )

@app.route('/health')
def health():
    return jsonify({
        'status': 'ok',
        'registros': len(registros),
        'timestamp': datetime.now().isoformat()
    })

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, threaded=True, debug=False)