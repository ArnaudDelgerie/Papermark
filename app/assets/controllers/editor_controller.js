import { Controller } from '@hotwired/stimulus';
import { Crepe } from '@milkdown/crepe'

export default class extends Controller {
    connect() {
        const crepe = new Crepe({
            root: this.element,
            defaultValue: 'Hello, Milkdown!',
        })

        crepe.create()
    }
}
