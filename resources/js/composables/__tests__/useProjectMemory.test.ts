import axios from 'axios';
import { describe, expect, it, vi } from 'vitest';
import { useProjectMemory } from '../useProjectMemory';

vi.mock('axios');

const mockedAxios = vi.mocked(axios, true);

describe('useProjectMemory', () => {
    it('archiveEntry removes the entry and returns true on success', async () => {
        mockedAxios.delete.mockResolvedValueOnce({ data: {} });

        const memory = useProjectMemory(9);
        memory.entries.value = [
            { id: 1, content: {}, confidence: 80 } as never,
            { id: 2, content: {}, confidence: 70 } as never,
        ];

        const ok = await memory.archiveEntry(1);

        expect(ok).toBe(true);
        expect(memory.entries.value.map(entry => entry.id)).toEqual([2]);
        expect(mockedAxios.delete).toHaveBeenCalledWith('/api/v1/projects/9/memory/1');
    });

    it('archiveEntry sets error and returns false on failure', async () => {
        mockedAxios.delete.mockRejectedValueOnce(new Error('boom'));

        const memory = useProjectMemory(9);
        memory.entries.value = [{ id: 1, content: {}, confidence: 80 } as never];

        const ok = await memory.archiveEntry(1);

        expect(ok).toBe(false);
        expect(memory.error.value).toBe('Failed to archive memory entry.');
        expect(memory.entries.value.map(entry => entry.id)).toEqual([1]);
    });
});
