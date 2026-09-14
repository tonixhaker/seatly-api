import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import Ajv2020 from 'ajv/dist/2020.js'
import addFormats from 'ajv-formats'

const here = dirname(fileURLToPath(import.meta.url))
const read = (...parts) => JSON.parse(readFileSync(join(here, ...parts), 'utf8'))

const names = ['envelope', 'event.published', 'order.paid', 'order.payment_failed', 'tickets.issued']

const expectedFailures = {
    'envelope': {
        reason: 'the envelope is missing event_id',
        keyword: 'required',
        instancePath: '',
        param: 'event_id',
    },
    'event.published': {
        reason: 'event_type is outside the four known values',
        keyword: 'const',
        instancePath: '/event_type',
        param: 'event.published',
    },
    'order.paid': {
        reason: 'the payload carries an extra property',
        keyword: 'additionalProperties',
        instancePath: '/payload',
        param: 'session_id',
    },
    'order.payment_failed': {
        reason: 'the envelope is missing version',
        keyword: 'required',
        instancePath: '',
        param: 'version',
    },
    'tickets.issued': {
        reason: 'the envelope is missing occurred_at',
        keyword: 'required',
        instancePath: '',
        param: 'occurred_at',
    },
}

const ajv = new Ajv2020({ allErrors: true, strict: true })
addFormats(ajv)

for (const name of names) {
    ajv.addSchema(read(`${name}.json`))
}

const failures = []
const pass = (line) => console.log(`  ok   ${line}`)
const fail = (line) => {
    console.log(`  FAIL ${line}`)
    failures.push(line)
}

const paramOf = (error) =>
    error.params.missingProperty ?? error.params.additionalProperty ?? error.params.allowedValue ?? null

for (const name of names) {
    const validate = ajv.getSchema(`https://schemas.seatly.dev/events/${name}.json`)

    if (validate(read('examples', `${name}.valid.json`))) {
        pass(`${name}: valid example validates`)
    } else {
        fail(`${name}: valid example was rejected — ${ajv.errorsText(validate.errors)}`)
    }

    const expected = expectedFailures[name]
    const accepted = validate(read('examples', `${name}.invalid.json`))
    const errors = validate.errors ?? []

    if (accepted) {
        fail(`${name}: invalid example was accepted — expected a failure because ${expected.reason}`)
        continue
    }

    const matches = errors.some(
        (error) =>
            error.keyword === expected.keyword &&
            error.instancePath === expected.instancePath &&
            paramOf(error) === expected.param
    )
    const stray = errors.filter((error) => error.instancePath !== expected.instancePath)

    if (matches && stray.length === 0) {
        pass(`${name}: invalid example fails because ${expected.reason}`)
    } else if (!matches) {
        fail(`${name}: invalid example failed for another reason — ${ajv.errorsText(errors)}`)
    } else {
        fail(`${name}: invalid example also failed incidentally — ${ajv.errorsText(stray)}`)
    }
}

const expectError = (name, document, label, match) => {
    const validate = ajv.getSchema(`https://schemas.seatly.dev/events/${name}.json`)
    const accepted = validate(document)
    const errors = validate.errors ?? []

    if (accepted) {
        fail(`${name}: ${label} — the document was accepted`)
    } else if (errors.some(match)) {
        pass(`${name}: ${label}`)
    } else {
        fail(`${name}: ${label} — rejected for another reason — ${ajv.errorsText(errors)}`)
    }
}

for (const name of names) {
    const valid = read('examples', `${name}.valid.json`)
    const { event_id, ...withoutEventId } = valid

    expectError(
        name,
        withoutEventId,
        "a document missing the envelope's event_id is rejected",
        (error) => error.keyword === 'required' && error.instancePath === '' && error.params.missingProperty === 'event_id'
    )

    expectError(
        name,
        { ...valid, correlation_id: 'x' },
        'an unknown envelope property is rejected',
        (error) =>
            error.keyword === 'additionalProperties' &&
            error.instancePath === '' &&
            error.params.additionalProperty === 'correlation_id'
    )

    expectError(
        name,
        { ...valid, event_id: 'not-a-uuid' },
        'an event_id that is not a uuid is rejected',
        (error) => error.keyword === 'format' && error.instancePath === '/event_id' && error.params.format === 'uuid'
    )

    expectError(
        name,
        { ...valid, occurred_at: '2026-10-01T18:42:11' },
        'an occurred_at without a timezone is rejected',
        (error) =>
            error.keyword === 'format' && error.instancePath === '/occurred_at' && error.params.format === 'date-time'
    )
}

expectError(
    'envelope',
    { ...read('examples', 'envelope.valid.json'), event_type: 'event.cancelled' },
    'an event_type outside the four known values is rejected',
    (error) => error.keyword === 'enum' && error.instancePath === '/event_type'
)

const foreignType = {
    'event.published': 'order.paid',
    'order.paid': 'tickets.issued',
    'order.payment_failed': 'event.published',
    'tickets.issued': 'order.payment_failed',
}

for (const name of names.slice(1)) {
    const valid = read('examples', `${name}.valid.json`)

    expectError(
        name,
        { ...valid, event_type: foreignType[name] },
        `an envelope carrying event_type ${foreignType[name]} is rejected`,
        (error) => error.keyword === 'const' && error.instancePath === '/event_type' && error.params.allowedValue === name
    )

    expectError(
        name,
        { ...valid, version: 2 },
        'an envelope carrying a version other than 1 is rejected',
        (error) => error.keyword === 'const' && error.instancePath === '/version' && error.params.allowedValue === 1
    )

    expectError(
        name,
        { ...valid, payload: { ...valid.payload, correlation_id: 'x' } },
        'an unknown payload property is rejected',
        (error) =>
            error.keyword === 'additionalProperties' &&
            error.instancePath === '/payload' &&
            error.params.additionalProperty === 'correlation_id'
    )

    for (const field of Object.keys(valid.payload)) {
        const { [field]: removed, ...payload } = valid.payload

        expectError(
            name,
            { ...valid, payload },
            `a payload missing ${field} is rejected`,
            (error) =>
                error.keyword === 'required' &&
                error.instancePath === '/payload' &&
                error.params.missingProperty === field
        )
    }
}

console.log(failures.length === 0 ? '\nevent schemas: all checks passed' : `\nevent schemas: ${failures.length} check(s) failed`)
process.exit(failures.length === 0 ? 0 : 1)
