// Prueba de carga del backend con k6 (https://k6.io) — RNF-13: tiempo de respuesta y capacidad.
//
// Dos escenarios, uno detrás del otro:
//   1. un_usuario:   1 usuario durante 30 segundos (la referencia sin carga)
//   2. diez_usuarios: 10 usuarios a la vez durante 30 segundos (el objetivo del RNF-13)
// Cada usuario repite: GET /estado (consulta la base) y POST /iniciar-sesion con un usuario inexistente
// (busca en la base y responde 401). Ninguna de las dos peticiones escribe en la base.
//
// Objetivo del RNF-13 (los "thresholds" de abajo): con 10 usuarios, p95 < 800 ms y menos de 0,1 % de errores.
// Si no se cumple, k6 lo marca con ✗ y termina con error.
//
// Cómo correrlo (con Docker, sin instalar k6), desde la raíz del backend:
//   docker run --rm -i grafana/k6 run - < tests/carga/carga.js
// Contra otra dirección (por ejemplo, el backend local):
//   docker run --rm -i -e URL_BASE=http://host.docker.internal:8000 grafana/k6 run - < tests/carga/carga.js
// Ver tests/carga/README.md.

import http from 'k6/http';
import { check } from 'k6';

// Por defecto, producción a través de Vercel: el mismo camino que usa el navegador
const URL_BASE = __ENV.URL_BASE || 'https://alojamientotemporario-frontend.vercel.app/api';

export const options = {
    scenarios: {
        un_usuario: {
            executor: 'constant-vus',
            vus: 1,
            duration: '30s',
        },
        diez_usuarios: {
            executor: 'constant-vus',
            vus: 10,
            duration: '30s',
            startTime: '35s', // empieza cuando termina el anterior
        },
    },
    thresholds: {
        // el objetivo del RNF-13
        'http_req_duration{scenario:diez_usuarios}': ['p(95)<800'],
        'http_req_failed{scenario:diez_usuarios}': ['rate<0.001'],
        // estos no tienen objetivo: están para que el resumen muestre sus números por separado
        'http_req_duration{scenario:un_usuario}': ['p(95)>=0'],
        'http_req_duration{endpoint:estado}': ['p(95)>=0'],
        'http_req_duration{endpoint:iniciar_sesion}': ['p(95)>=0'],
    },
    summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
};

export default function () {
    const estado = http.get(`${URL_BASE}/estado`, { tags: { endpoint: 'estado' } });
    check(estado, {
        'estado responde 200': (r) => r.status === 200,
        'la base de datos responde': (r) => r.json('chequeos.base_de_datos') === 'ok',
    });

    const login = http.post(
        `${URL_BASE}/iniciar-sesion`,
        JSON.stringify({ mail: 'carga-k6@prueba.local', contrasenia: 'clave-de-prueba' }),
        {
            headers: { 'Content-Type': 'application/json' },
            tags: { endpoint: 'iniciar_sesion' },
            // un 401 es la respuesta esperada (el usuario no existe): no se cuenta como error
            responseCallback: http.expectedStatuses(401),
        }
    );
    check(login, { 'login responde 401 (usuario inexistente)': (r) => r.status === 401 });
}
